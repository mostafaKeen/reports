<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ReportService
{
    protected BitrixClient $client;
    protected CompanyCacheService $cache;
    protected Company $company;
    protected $progressCallback = null;

    // Cache TTLs
    protected const CACHE_TTL_FIELDS = 86400 * 7;      // 7 days for field definitions
    protected const CACHE_TTL_SOURCES = 86400;          // 24 hours for sources
    protected const CACHE_TTL_LEADS = 300;              // 5 minutes for leads
    protected const CACHE_TTL_ACTIVITIES = 300;         // 5 minutes for activities

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->client = new BitrixClient($company);
        $this->cache = new CompanyCacheService($company);
    }

    /**
     * Set a progress callback for real-time updates.
     * Callback receives (percentage: int, stage: string)
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Report progress to the callback.
     */
    protected function reportProgress(int $percent, string $stage): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $percent, $stage);
        }
    }

    /**
     * Resolve the "Listing Reference" custom field ID.
     * Cached for 7 days since fields rarely change.
     */
    public function getListingReferenceFieldId(): ?string
    {
        $cacheKey = 'listing_ref_field';
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->client->call('crm.lead.fields');
            $fields = $response['result'] ?? [];

            foreach ($fields as $fieldId => $fieldMeta) {
                $title = $fieldMeta['title'] ?? $fieldMeta['formLabel'] ?? $fieldMeta['listLabel'] ?? '';
                if (stripos($title, 'Listing Reference') !== false) {
                    $this->cache->set($cacheKey, $fieldId, self::CACHE_TTL_FIELDS);
                    return $fieldId;
                }
            }

            Log::warning('Listing Reference field not found', ['company_id' => $this->company->id]);
            return null;

        } catch (\Exception $e) {
            Log::error('Failed to get listing reference field', [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function getDailyKeys(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate, 'Asia/Dubai')->startOfDay();
        $end = Carbon::parse($endDate, 'Asia/Dubai')->startOfDay();
        $days = [];

        while ($start->lte($end)) {
            $days[] = $start->format('Y-m-d');
            $start->addDay();
        }

        return $days;
    }

    protected function buildMissingRanges(array $days): array
    {
        sort($days);
        $ranges = [];
        $rangeStart = null;
        $rangeEnd = null;

        foreach ($days as $day) {
            if ($rangeStart === null) {
                $rangeStart = $day;
                $rangeEnd = $day;
                continue;
            }

            $nextDay = Carbon::parse($rangeEnd, 'Asia/Dubai')->addDay()->format('Y-m-d');
            if ($day === $nextDay) {
                $rangeEnd = $day;
                continue;
            }

            $ranges[] = [$rangeStart, $rangeEnd];
            $rangeStart = $day;
            $rangeEnd = $day;
        }

        if ($rangeStart !== null) {
            $ranges[] = [$rangeStart, $rangeEnd];
        }

        return $ranges;
    }

    protected function getUserNameMap(array $userIds): array
    {
        $userIds = array_values(array_filter(array_unique($userIds), function ($id) {
            return $id !== null && $id !== '';
        }));

        if (empty($userIds)) {
            return [];
        }

        // Normalize all IDs to strings for consistent cache key matching
        $userIds = array_map('strval', $userIds);

        $cacheKey = 'user_names';
        $cached = $this->cache->get($cacheKey) ?? [];
        $cachedIds = array_keys($cached);
        $missingIds = array_values(array_diff($userIds, $cachedIds));

        if (!empty($missingIds)) {
            $fresh = $this->fetchUsersByIds($missingIds);
            $cached = array_merge($cached, $fresh);
            $this->cache->set($cacheKey, $cached, self::CACHE_TTL_SOURCES);
        }

        $result = [];
        foreach ($userIds as $id) {
            $idStr = (string) $id;
            if (isset($cached[$idStr])) {
                $result[$idStr] = $cached[$idStr];
            } else {
                $result[$idStr] = "User #{$idStr}";
            }
        }

        return $result;
    }

    protected function fetchUsersByIds(array $userIds): array
    {
        $userMap = [];
        // Ensure all IDs are strings
        $userIds = array_map('strval', array_filter($userIds));

        if (empty($userIds)) {
            return [];
        }

        $chunkSize = 50;
        $chunks = array_chunk($userIds, $chunkSize);

        foreach ($chunks as $chunk) {
            $start = 0;
            do {
                $response = $this->client->call('user.get', [
                    'filter' => ['ID' => $chunk],
                    'select' => ['ID', 'NAME', 'LAST_NAME', 'EMAIL'],
                    'order' => ['ID' => 'ASC'],
                    'start' => $start,
                ]);

                foreach (($response['result'] ?? []) as $user) {
                    $id = (string) ($user['ID'] ?? null);
                    if (!$id) {
                        continue;
                    }

                    $fullName = trim(($user['NAME'] ?? '') . ' ' . ($user['LAST_NAME'] ?? '')) ?: ($user['EMAIL'] ?? "User #{$id}");
                    $userMap[$id] = $fullName;
                }

                $start = $response['next'] ?? null;
            } while ($start !== null);
        }

        return $userMap;
    }

    protected function cacheKeyForDate(string $type, string $date): string
    {
        return "{$type}:date:{$date}";
    }

    /**
     * Fetch all leads within date range with pagination.
     * Cached by day to allow partial range reuse.
     */
    public function fetchLeads(string $startDate, string $endDate, array $selectFields = []): array
    {
        $listingRefField = $this->getListingReferenceFieldId();
        $select = array_merge([
            'ID', 'TITLE', 'DATE_CREATE', 'SOURCE_ID', 'STATUS_ID', 'ASSIGNED_BY_ID',
        ], $selectFields);

        if ($listingRefField) {
            $select[] = $listingRefField;
        }

        $allLeads = [];
        $days = $this->getDailyKeys($startDate, $endDate);
        $cachedLeads = [];
        $missingDays = [];

        foreach ($days as $day) {
            $dayCache = $this->cache->get($this->cacheKeyForDate('leads', $day));
            if ($dayCache !== null) {
                $cachedLeads = array_merge($cachedLeads, $dayCache);
            } else {
                $missingDays[] = $day;
            }
        }

        $freshLeads = [];
        if (!empty($missingDays)) {
            $ranges = $this->buildMissingRanges($missingDays);
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                $this->reportProgress(20, "Fetching leads {$rangeStart} to {$rangeEnd}");
                $rangeLeads = $this->fetchAndCacheLeadsRange($rangeStart, $rangeEnd, $select);
                $freshLeads = array_merge($freshLeads, $rangeLeads);
            }
        }

        $allLeads = array_merge($cachedLeads, $freshLeads);

        Log::info('Leads fetched successfully', [
            'company_id' => $this->company->id,
            'total_leads' => count($allLeads),
            'cached_days' => count($days) - count($missingDays),
            'refreshed_days' => count($missingDays),
            'cached_count' => count($cachedLeads),
            'fresh_count' => count($freshLeads),
        ]);

        return $allLeads;
    }

    protected function fetchAndCacheLeadsRange(string $rangeStart, string $rangeEnd, array $select): array
    {
        $allLeads = [];
        $start = 0;

        try {
            do {
                Log::debug('Fetching leads batch for range', [
                    'company_id' => $this->company->id,
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                    'offset' => $start,
                ]);

                $response = $this->client->call('crm.lead.list', [
                    'filter' => [
                        '>=DATE_CREATE' => Carbon::parse($rangeStart, 'Asia/Dubai')->startOfDay()->format('Y-m-d H:i:s'),
                        '<=DATE_CREATE' => Carbon::parse($rangeEnd, 'Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s'),
                    ],
                    'select' => $select,
                    'order' => ['DATE_CREATE' => 'ASC'],
                    'start' => $start,
                ]);

                $leads = $response['result'] ?? [];
                $allLeads = array_merge($allLeads, $leads);

                $next = $response['next'] ?? null;
                $start = $next;
            } while ($next !== null);

            $dayBuckets = [];
            foreach ($allLeads as $lead) {
                $dateValue = $lead['DATE_CREATE'] ?? null;
                if (!$dateValue) {
                    continue;
                }

                $day = Carbon::parse($dateValue, 'Asia/Dubai')->format('Y-m-d');
                $key = $this->cacheKeyForDate('leads', $day);
                $dayBuckets[$key][] = $lead;
            }

            foreach ($this->getDailyKeys($rangeStart, $rangeEnd) as $day) {
                $cacheKey = $this->cacheKeyForDate('leads', $day);
                $bucket = $dayBuckets[$cacheKey] ?? [];
                $this->cache->set($cacheKey, $bucket, self::CACHE_TTL_LEADS);
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch leads range', [
                'company_id' => $this->company->id,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $allLeads;
    }

    /**
     * Fetch all activities within date range.
     * Cached for 5 minutes.
     */
    public function fetchActivities(string $startDate, string $endDate): array
    {
        $allActivities = [];
        $days = $this->getDailyKeys($startDate, $endDate);
        $cachedActivities = [];
        $missingDays = [];

        foreach ($days as $day) {
            $dayCache = $this->cache->get($this->cacheKeyForDate('activities', $day));
            if ($dayCache !== null) {
                $cachedActivities = array_merge($cachedActivities, $dayCache);
            } else {
                $missingDays[] = $day;
            }
        }

        $freshActivities = [];
        if (!empty($missingDays)) {
            $ranges = $this->buildMissingRanges($missingDays);
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                $this->reportProgress(45, "Fetching activities {$rangeStart} to {$rangeEnd}");
                $rangeActivities = $this->fetchAndCacheActivitiesRange($rangeStart, $rangeEnd);
                $freshActivities = array_merge($freshActivities, $rangeActivities);
            }
        }

        $allActivities = array_merge($cachedActivities, $freshActivities);

        Log::info('Activities fetched successfully', [
            'company_id' => $this->company->id,
            'total_activities' => count($allActivities),
            'cached_days' => count($days) - count($missingDays),
            'refreshed_days' => count($missingDays),
            'cached_count' => count($cachedActivities),
            'fresh_count' => count($freshActivities),
        ]);

        return $allActivities;
    }

    protected function fetchAndCacheActivitiesRange(string $rangeStart, string $rangeEnd): array
    {
        $allActivities = [];
        $start = 0;

        try {
            do {
                Log::debug('Fetching activities batch for range', [
                    'company_id' => $this->company->id,
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                    'offset' => $start,
                ]);

                $response = $this->client->call('crm.activity.list', [
                    'filter' => [
                        '>=CREATED' => Carbon::parse($rangeStart, 'Asia/Dubai')->startOfDay()->format('Y-m-d H:i:s'),
                        '<=CREATED' => Carbon::parse($rangeEnd, 'Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s'),
                    ],
                    'select' => [
                        'ID', 'SUBJECT', 'TYPE_ID', 'OWNER_ID', 'OWNER_TYPE_ID',
                        'CREATED', 'COMPLETED', 'RESPONSIBLE_ID', 'DIRECTION',
                    ],
                    'order' => ['CREATED' => 'ASC'],
                    'start' => $start,
                ]);

                $activities = $response['result'] ?? [];
                $allActivities = array_merge($allActivities, $activities);

                $next = $response['next'] ?? null;
                $start = $next;
            } while ($next !== null);

            $dayBuckets = [];
            foreach ($allActivities as $activity) {
                $dateValue = $activity['CREATED'] ?? null;
                if (!$dateValue) {
                    continue;
                }

                $day = Carbon::parse($dateValue, 'Asia/Dubai')->format('Y-m-d');
                $key = $this->cacheKeyForDate('activities', $day);
                $dayBuckets[$key][] = $activity;
            }

            foreach ($this->getDailyKeys($rangeStart, $rangeEnd) as $day) {
                $cacheKey = $this->cacheKeyForDate('activities', $day);
                $bucket = $dayBuckets[$cacheKey] ?? [];
                $this->cache->set($cacheKey, $bucket, self::CACHE_TTL_ACTIVITIES);
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch activities range', [
                'company_id' => $this->company->id,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $allActivities;
    }

    /**
     * Fetch lead sources.
     * Cached for 24 hours.
     */
    public function fetchLeadSources(): array
    {
        $cacheKey = 'lead_sources';
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            Log::debug("Using cached lead sources", [
                'company_id' => $this->company->id,
                'count' => count($cached)
            ]);
            return $cached;
        }

        try {
            $response = $this->client->call('crm.status.list', [
                'filter' => ['ENTITY_ID' => 'SOURCE'],
            ]);

            $sources = [];
            foreach (($response['result'] ?? []) as $source) {
                $sources[$source['STATUS_ID']] = $source['NAME'];
            }

            $this->cache->set($cacheKey, $sources, self::CACHE_TTL_SOURCES);

            Log::info("Lead sources fetched", [
                'company_id' => $this->company->id,
                'count' => count($sources)
            ]);

            return $sources;

        } catch (\Exception $e) {
            Log::error("Failed to fetch lead sources", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Aggregate report data from all sources.
     * Intelligent caching to minimize API calls.
     */
    public function aggregateReport(string $startDate, string $endDate): array
    {
        Log::info("Generating report", [
            'company_id' => $this->company->id,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        try {
            // Fetch all data (with caching)
            $this->reportProgress(10, 'Fetching leads');
            $leads = $this->fetchLeads($startDate, $endDate);
            
            $this->reportProgress(40, 'Fetching activities');
            $activities = $this->fetchActivities($startDate, $endDate);
            
            $this->reportProgress(60, 'Loading sources');
            $sources = $this->fetchLeadSources();
            
            $this->reportProgress(70, 'Processing data');
            $listingRefField = $this->getListingReferenceFieldId();

            // Process leads
            $totalLeads = count($leads);
            $leadsBySource = [];
            foreach ($leads as $lead) {
                $sourceId = $lead['SOURCE_ID'] ?? 'UNKNOWN';
                $sourceName = $sources[$sourceId] ?? $sourceId;
                $leadsBySource[$sourceName] = ($leadsBySource[$sourceName] ?? 0) + 1;
            }
            arsort($leadsBySource);

            // Process listing references
            $leadsByListingRef = [];
            if ($listingRefField) {
                foreach ($leads as $lead) {
                    $refValue = $lead[$listingRefField] ?? null;
                    if (!empty($refValue)) {
                        $leadsByListingRef[$refValue] = ($leadsByListingRef[$refValue] ?? 0) + 1;
                    } else {
                        $leadsByListingRef['(Empty)'] = ($leadsByListingRef['(Empty)'] ?? 0) + 1;
                    }
                }
                arsort($leadsByListingRef);
            }

            // Process activities
            $totalActivities = count($activities);
            $activityTypeMap = [
                1 => 'Task',
                2 => 'Meeting',
                3 => 'Call',
                4 => 'Email',
                5 => 'Provider',
                6 => 'SMS',
                7 => 'Visit',
            ];

            $activitiesByType = [];
            foreach ($activities as $activity) {
                $typeId = $activity['TYPE_ID'] ?? 0;
                $typeName = $activityTypeMap[$typeId] ?? "Type #{$typeId}";
                $activitiesByType[$typeName] = ($activitiesByType[$typeName] ?? 0) + 1;
            }
            arsort($activitiesByType);

            // Get user analytics
            $userAnalytics = $this->getUserAnalytics($leads, $activities);

            $this->reportProgress(95, 'Finalizing');
            
            $report = [
                'total_leads' => $totalLeads,
                'leads_by_source' => $leadsBySource,
                'leads_by_listing_ref' => $leadsByListingRef,
                'listing_ref_field_id' => $listingRefField,
                'total_activities' => $totalActivities,
                'activities_by_type' => $activitiesByType,
                'user_analytics' => $userAnalytics,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'generated_at' => Carbon::now('Asia/Dubai')->toDateTimeString(),
            ];

            Log::info("Report generated successfully", [
                'company_id' => $this->company->id,
                'total_leads' => $totalLeads,
                'total_activities' => $totalActivities,
                'unique_sources' => count($leadsBySource),
                'unique_users' => count($userAnalytics)
            ]);

            return $report;

        } catch (\Exception $e) {
            Log::error("Failed to generate report", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get users with their activity counts and lead assignments.
     */
    public function getUserAnalytics(array $leads, array $activities): array
    {
        $this->reportProgress(75, 'Analyzing user activity');

        $activityTypeMap = [
            1 => 'Task',
            2 => 'Meeting',
            3 => 'Call',
            4 => 'Email',
            5 => 'Provider',
            6 => 'SMS',
            7 => 'Visit',
        ];

        // Track activities by responsible user
        $userActivities = [];
        foreach ($activities as $activity) {
            $userId = $activity['RESPONSIBLE_ID'] ?? null;
            if (!$userId) {
                continue;
            }

            if (!isset($userActivities[$userId])) {
                $userActivities[$userId] = [
                    'total_activities' => 0,
                    'activities_by_type' => [],
                ];
            }

            $userActivities[$userId]['total_activities']++;

            $typeId = $activity['TYPE_ID'] ?? 0;
            $typeName = $activityTypeMap[$typeId] ?? "Type #{$typeId}";
            $userActivities[$userId]['activities_by_type'][$typeName] =
                ($userActivities[$userId]['activities_by_type'][$typeName] ?? 0) + 1;
        }

        // Track leads by assigned user
        $userLeads = [];
        foreach ($leads as $lead) {
            $userId = $lead['ASSIGNED_BY_ID'] ?? null;
            if (!$userId) {
                continue;
            }

            if (!isset($userLeads[$userId])) {
                $userLeads[$userId] = 0;
            }

            $userLeads[$userId]++;
        }

        // Merge user data
        $users = [];
        $allUserIds = array_unique(array_merge(array_keys($userActivities), array_keys($userLeads)));
        $userNames = $this->getUserNameMap($allUserIds);

        foreach ($allUserIds as $userId) {
            $users[$userId] = [
                'user_id' => $userId,
                'user_name' => $userNames[$userId] ?? "User #{$userId}",
                'total_leads' => $userLeads[$userId] ?? 0,
                'total_activities' => $userActivities[$userId]['total_activities'] ?? 0,
                'activities_by_type' => $userActivities[$userId]['activities_by_type'] ?? [],
            ];
        }

        // Sort by total activities descending
        usort($users, fn($a, $b) => $b['total_activities'] <=> $a['total_activities']);

        return $users;
    }

    /**
     * Clear cached report data.
     */
    public function clearCache(): void
    {
        Log::info("Clearing report cache", ['company_id' => $this->company->id]);
        $this->cache->flush();
    }
}