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

    /**
     * Fetch all leads within date range with pagination.
     * Cached for 5 minutes.
     */
    public function fetchLeads(string $startDate, string $endDate, array $selectFields = []): array
    {
        $cacheKey = "leads:" . md5("{$startDate}_{$endDate}");
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            Log::debug("Using cached leads", [
                'company_id' => $this->company->id,
                'count' => count($cached)
            ]);
            return $cached;
        }

        $listingRefField = $this->getListingReferenceFieldId();
        $select = array_merge([
            'ID', 'TITLE', 'DATE_CREATE', 'SOURCE_ID', 'STATUS_ID', 'ASSIGNED_BY_ID',
        ], $selectFields);

        if ($listingRefField) {
            $select[] = $listingRefField;
        }

        $allLeads = [];
        $start = 0;

        try {
            do {
                Log::debug("Fetching leads batch", [
                    'company_id' => $this->company->id,
                    'offset' => $start
                ]);

                $response = $this->client->call('crm.lead.list', [
                    'filter' => [
                        '>=DATE_CREATE' => $startDate,
                        '<=DATE_CREATE' => $endDate,
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

            $this->cache->set($cacheKey, $allLeads, self::CACHE_TTL_LEADS);

            Log::info("Leads fetched successfully", [
                'company_id' => $this->company->id,
                'total_leads' => count($allLeads)
            ]);

            return $allLeads;

        } catch (\Exception $e) {
            Log::error("Failed to fetch leads", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Fetch all activities within date range.
     * Cached for 5 minutes.
     */
    public function fetchActivities(string $startDate, string $endDate): array
    {
        $cacheKey = "activities:" . md5("{$startDate}_{$endDate}");
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            Log::debug("Using cached activities", [
                'company_id' => $this->company->id,
                'count' => count($cached)
            ]);
            return $cached;
        }

        $allActivities = [];
        $start = 0;

        try {
            do {
                Log::debug("Fetching activities batch", [
                    'company_id' => $this->company->id,
                    'offset' => $start
                ]);

                $response = $this->client->call('crm.activity.list', [
                    'filter' => [
                        '>=CREATED' => $startDate,
                        '<=CREATED' => $endDate,
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

            $this->cache->set($cacheKey, $allActivities, self::CACHE_TTL_ACTIVITIES);

            Log::info("Activities fetched successfully", [
                'company_id' => $this->company->id,
                'total_activities' => count($allActivities)
            ]);

            return $allActivities;

        } catch (\Exception $e) {
            Log::error("Failed to fetch activities", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
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

        foreach ($allUserIds as $userId) {
            $users[$userId] = [
                'user_id' => $userId,
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