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

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->client = new BitrixClient($company);
        $this->cache = new CompanyCacheService($company);
    }

    /**
     * Resolve the "Listing Reference" custom field ID by scanning crm.lead.fields.
     * Caches the result for 24 hours.
     */
    public function getListingReferenceFieldId(): ?string
    {
        $cacheKey = 'report:listing_ref_field';
        $cached = $this->cache->get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $response = $this->client->call('crm.lead.fields');
        $fields = $response['result'] ?? [];

        foreach ($fields as $fieldId => $fieldMeta) {
            // Match by title (case-insensitive) — look for "Listing Reference"
            $title = $fieldMeta['title'] ?? $fieldMeta['formLabel'] ?? $fieldMeta['listLabel'] ?? '';
            if (stripos($title, 'Listing Reference') !== false) {
                $this->cache->set($cacheKey, $fieldId, 86400); // 24h cache
                return $fieldId;
            }
        }

        Log::warning('Listing Reference field not found in crm.lead.fields', [
            'company_id' => $this->company->id
        ]);

        return null;
    }

    /**
     * Fetch all leads within a date range (handles Bitrix24 pagination).
     * Dates should be in Asia/Dubai timezone.
     */
    public function fetchLeads(string $startDate, string $endDate, array $selectFields = []): array
    {
        $cacheKey = "report:leads:" . md5("{$startDate}_{$endDate}");
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $listingRefField = $this->getListingReferenceFieldId();

        $select = array_merge([
            'ID', 'TITLE', 'DATE_CREATE', 'SOURCE_ID', 'STATUS_ID', 'ASSIGNED_BY_ID',
        ], $selectFields);

        // Add the listing reference custom field if found
        if ($listingRefField) {
            $select[] = $listingRefField;
        }

        $allLeads = [];
        $start = 0;

        do {
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

            // Bitrix returns 'next' when there are more pages
            $next = $response['next'] ?? null;
            $start = $next;
        } while ($next !== null);

        // Cache for 5 minutes
        $this->cache->set($cacheKey, $allLeads, 300);

        return $allLeads;
    }

    /**
     * Fetch all activities within a date range (handles Bitrix24 pagination).
     */
    public function fetchActivities(string $startDate, string $endDate): array
    {
        $cacheKey = "report:activities:" . md5("{$startDate}_{$endDate}");
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $allActivities = [];
        $start = 0;

        do {
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

        // Cache for 5 minutes
        $this->cache->set($cacheKey, $allActivities, 300);

        return $allActivities;
    }

    /**
     * Fetch lead sources (crm.status.list where ENTITY_ID = SOURCE).
     */
    public function fetchLeadSources(): array
    {
        $cacheKey = 'report:lead_sources';
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $response = $this->client->call('crm.status.list', [
            'filter' => ['ENTITY_ID' => 'SOURCE'],
        ]);

        $sources = [];
        foreach (($response['result'] ?? []) as $source) {
            $sources[$source['STATUS_ID']] = $source['NAME'];
        }

        // Cache for 24 hours
        $this->cache->set($cacheKey, $sources, 86400);

        return $sources;
    }

    /**
     * Aggregate all metrics from leads and activities.
     */
    public function aggregateReport(string $startDate, string $endDate): array
    {
        $leads = $this->fetchLeads($startDate, $endDate);
        $activities = $this->fetchActivities($startDate, $endDate);
        $sources = $this->fetchLeadSources();
        $listingRefField = $this->getListingReferenceFieldId();

        // --- Lead Metrics ---
        $totalLeads = count($leads);

        // Group leads by SOURCE_ID
        $leadsBySource = [];
        foreach ($leads as $lead) {
            $sourceId = $lead['SOURCE_ID'] ?? 'UNKNOWN';
            $sourceName = $sources[$sourceId] ?? $sourceId;
            $leadsBySource[$sourceName] = ($leadsBySource[$sourceName] ?? 0) + 1;
        }
        arsort($leadsBySource);

        // Group leads by Listing Reference
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

        // --- Activity Metrics ---
        $totalActivities = count($activities);

        // Map activity type IDs to human-readable names
        $activityTypeMap = [
            1 => 'Task',
            2 => 'Meeting',
            3 => 'Call',
            4 => 'Email',
            5 => 'Provider',  // SMS / Open Channel etc.
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

        return [
            'total_leads' => $totalLeads,
            'leads_by_source' => $leadsBySource,
            'leads_by_listing_ref' => $leadsByListingRef,
            'listing_ref_field_id' => $listingRefField,
            'total_activities' => $totalActivities,
            'activities_by_type' => $activitiesByType,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'generated_at' => Carbon::now('Asia/Dubai')->toDateTimeString(),
        ];
    }

    /**
     * Clear all cached report data for this company.
     */
    public function clearCache(): void
    {
        // Use Redis SCAN to find and delete all report keys
        $prefix = "company:{$this->company->id}:report:";
        $iterator = null;

        do {
            $keys = \Illuminate\Support\Facades\Redis::scan($iterator, [
                'MATCH' => $prefix . '*',
                'COUNT' => 100,
            ]);

            if ($keys && is_array($keys[1]) && count($keys[1]) > 0) {
                \Illuminate\Support\Facades\Redis::del(...$keys[1]);
            }

            $iterator = $keys[0] ?? null;
        } while ($iterator && $iterator !== '0' && $iterator !== 0);
    }
}
