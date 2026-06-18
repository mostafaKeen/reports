<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * PropertyReferenceAnalyzer - Dynamically finds and analyzes Property Reference field
 * 
 * This service handles:
 * 1. Dynamic discovery of Property Reference field (field ID varies per company)
 * 2. Aggregation of leads by property reference
 * 3. Detection and handling of empty/null property references
 * 4. Caching of field definitions (7 days)
 */
class PropertyReferenceAnalyzer
{
    protected BitrixClient $client;
    protected CompanyCacheService $cache;
    protected Company $company;
    
    protected const CACHE_TTL_FIELDS = 86400 * 7; // 7 days
    protected const PROPERTY_REF_FIELD_CACHE_KEY = 'property_reference_field_id';

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->client = new BitrixClient($company);
        $this->cache = new CompanyCacheService($company);
    }

    /**
     * Dynamically find the Property Reference field ID
     * The field ID varies between companies (e.g., UF_CRM_1772139089925)
     * but the label is consistently "Property Reference"
     * 
     * @return array|null Returns ['field_id' => 'UF_CRM_...', 'title' => 'Property Reference'] or null
     */
    public function findPropertyReferenceField(): ?array
    {
        // Check cache first
        $cached = $this->cache->get(self::PROPERTY_REF_FIELD_CACHE_KEY);
        if ($cached !== null) {
            Log::debug("Using cached Property Reference field", [
                'company_id' => $this->company->id,
                'field_id' => $cached['field_id']
            ]);
            return $cached;
        }

        try {
            Log::info("Discovering Property Reference field from Bitrix24", [
                'company_id' => $this->company->id,
                'domain' => $this->company->domain
            ]);

            // Call Bitrix24 API to get all lead fields
            $response = $this->client->call('crm.lead.fields');
            $fields = $response['result'] ?? [];

            // Search for Property Reference field
            foreach ($fields as $fieldId => $fieldMeta) {
                // Check multiple label sources (title, formLabel, listLabel)
                $title = $fieldMeta['title'] ?? '';
                $formLabel = $fieldMeta['formLabel'] ?? '';
                $listLabel = $fieldMeta['listLabel'] ?? '';
                
                // Match "Property Reference" (case-insensitive)
                if ($this->isPropertyReferenceField($title, $formLabel, $listLabel)) {
                    $result = [
                        'field_id' => $fieldId,
                        'title' => $listLabel ?: $formLabel ?: $title,
                        'type' => $fieldMeta['type'] ?? 'string',
                        'is_multiple' => $fieldMeta['isMultiple'] ?? false,
                    ];

                    // Cache for 7 days
                    $this->cache->set(self::PROPERTY_REF_FIELD_CACHE_KEY, $result, self::CACHE_TTL_FIELDS);

                    Log::info("Property Reference field discovered", [
                        'company_id' => $this->company->id,
                        'field_id' => $fieldId,
                        'type' => $result['type']
                    ]);

                    return $result;
                }
            }

            Log::warning("Property Reference field not found in Bitrix24", [
                'company_id' => $this->company->id,
                'total_fields' => count($fields)
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Failed to discover Property Reference field", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if a field is the Property Reference field
     */
    private function isPropertyReferenceField(string $title, string $formLabel, string $listLabel): bool
    {
        $labels = [$title, $formLabel, $listLabel];
        
        foreach ($labels as $label) {
            if (empty($label)) {
                continue;
            }
            // Match "Property Reference" exactly (case-insensitive)
            if (strtolower($label) === 'property reference') {
                return true;
            }
        }

        return false;
    }

    /**
     * Aggregate leads by property reference
     * Returns array with property reference as key and lead count as value
     * 
     * @param array $leads Array of lead objects from Bitrix24
     * @param string $propertyRefFieldId The field ID (e.g., UF_CRM_1772139089925)
     * @return array Sorted array [property_reference => lead_count]
     */
    public function aggregateLeadsByPropertyReference(array $leads, string $propertyRefFieldId): array
    {
        $aggregation = [];
        $emptyCount = 0;

        foreach ($leads as $lead) {
            $refValue = $lead[$propertyRefFieldId] ?? null;

            if (!empty($refValue)) {
                // Handle multiple values if field type is multiple
                $values = is_array($refValue) ? $refValue : [$refValue];
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if (!empty($value)) {
                        $aggregation[$value] = ($aggregation[$value] ?? 0) + 1;
                    } else {
                        $emptyCount++;
                    }
                }
            } else {
                $emptyCount++;
            }
        }

        // Add empty reference count if any
        if ($emptyCount > 0) {
            $aggregation['(Empty)'] = $emptyCount;
        }

        // Sort by count descending
        arsort($aggregation);

        Log::debug("Property Reference aggregation completed", [
            'company_id' => $this->company->id,
            'unique_properties' => count($aggregation),
            'total_leads' => count($leads),
            'empty_references' => $emptyCount
        ]);

        return $aggregation;
    }

    /**
     * Get detailed analytics for property references
     * Useful for UI display and detailed reporting
     * 
     * @param array $leads Array of lead objects
     * @param string $propertyRefFieldId The field ID
     * @return array Detailed analytics with percentages
     */
    public function getPropertyReferenceAnalytics(array $leads, string $propertyRefFieldId): array
    {
        $aggregation = $this->aggregateLeadsByPropertyReference($leads, $propertyRefFieldId);
        $totalLeads = count($leads);

        $analytics = [
            'total_leads' => $totalLeads,
            'unique_properties' => count(array_filter($aggregation, fn($k) => $k !== '(Empty)', ARRAY_FILTER_USE_KEY)),
            'properties' => [],
        ];

        foreach ($aggregation as $refValue => $count) {
            $percentage = $totalLeads > 0 ? round(($count / $totalLeads) * 100, 2) : 0;
            
            $analytics['properties'][] = [
                'reference' => $refValue,
                'lead_count' => $count,
                'percentage' => $percentage,
            ];
        }

        return $analytics;
    }

    /**
     * Clear cached field definition when field structure changes
     */
    public function clearFieldCache(): void
    {
        $this->cache->delete(self::PROPERTY_REF_FIELD_CACHE_KEY);
        Log::info("Property Reference field cache cleared", [
            'company_id' => $this->company->id
        ]);
    }

    /**
     * Get field cache key for external use
     */
    public static function getCacheKey(): string
    {
        return self::PROPERTY_REF_FIELD_CACHE_KEY;
    }
}