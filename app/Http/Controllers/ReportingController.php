<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ReportService;
use App\Services\PropertyReferenceAnalyzer;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced ReportingController with Property Reference Support
 * 
 * Features:
 * - Automatic detection of Listing Reference/Property Reference fields
 * - Detailed diagnostics endpoint for debugging
 * - CSV export with property reference breakdown
 * - Support for both field IDs: UF_CRM_1772139089925 and UF_CRM_1774618391777
 */
class ReportingController extends Controller
{
    /**
     * Show the report page.
     */
    public function show(Request $request, Company $company)
    {
        // Default date range: last 30 days in Dubai timezone
        $startDate = $request->get('start_date', Carbon::now('Asia/Dubai')->subDays(30)->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::now('Asia/Dubai')->format('Y-m-d'));

        // Detect if this page is opened from Bitrix24 iframe
        $isBitrix24Context = $this->isBitrix24Context($request);

        // Get all active companies for the dropdown (only if NOT in Bitrix24 context)
        $companies = $isBitrix24Context ? collect() : Company::where('is_active', true)->get();

        return view('reports.index', [
            'company' => $company,
            'companies' => $companies,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'isBitrix24Context' => $isBitrix24Context,
        ]);
    }

    /**
     * Diagnostic endpoint to check field configuration
     * GET /report/{company}/diagnostics
     */
    public function diagnostics(Request $request, Company $company)
    {
        try {
            Log::info('Running diagnostics', ['company_id' => $company->id]);

            $service = new ReportService($company);
            
            // Get the listing reference field
            $listingRefField = $service->getListingReferenceFieldId();

            // Try to fetch a sample lead to verify data is working
            $sampleLeads = $service->fetchLeads(
                Carbon::now('Asia/Dubai')->subDays(7)->format('Y-m-d H:i:s'),
                Carbon::now('Asia/Dubai')->format('Y-m-d H:i:s')
            );

            $diagnosticData = [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'listing_ref_field_id' => $listingRefField,
                'sample_leads_count' => count($sampleLeads),
                'sample_lead_with_ref' => null,
                'field_values_sample' => [],
                'status' => 'ok',
            ];

            // Find a lead with listing reference data if field is found
            if ($listingRefField && !empty($sampleLeads)) {
                $fieldValuesFound = [];
                foreach ($sampleLeads as $lead) {
                    $refValue = $lead[$listingRefField] ?? null;
                    if (!empty($refValue)) {
                        $fieldValuesFound[] = $refValue;
                        if ($diagnosticData['sample_lead_with_ref'] === null) {
                            $diagnosticData['sample_lead_with_ref'] = [
                                'lead_id' => $lead['ID'] ?? null,
                                'lead_title' => $lead['TITLE'] ?? null,
                                'field_value' => $refValue,
                            ];
                        }
                    }
                }
                $diagnosticData['field_values_sample'] = array_unique(array_slice($fieldValuesFound, 0, 5));
                $diagnosticData['unique_field_values'] = count(array_unique($fieldValuesFound));
            } else {
                $diagnosticData['status'] = 'warning';
                $diagnosticData['message'] = $listingRefField 
                    ? 'Field found but no sample leads with values'
                    : 'Listing Reference field not detected';
            }

            Log::info('Diagnostics completed', $diagnosticData);

            return response()->json([
                'success' => true,
                'data' => $diagnosticData,
            ]);

        } catch (\Exception $e) {
            Log::error('Diagnostics error', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Fetch report data (AJAX endpoint).
     * Emits progress updates as JSON chunks.
     */
    public function fetchData(Request $request, Company $company)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'force_refresh' => 'sometimes|boolean',
        ]);

        $startDate = Carbon::parse($request->start_date, 'Asia/Dubai')->startOfDay()->format('Y-m-d H:i:s');
        $endDate = Carbon::parse($request->end_date, 'Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s');
        $forceRefresh = $request->boolean('force_refresh', false);

        try {
            $service = new ReportService($company);

            // Clear cache if user requested fresh data
            if ($forceRefresh) {
                $service->clearCache();
            }

            // Set up progress tracking
            $progressUpdates = [];
            $service->setProgressCallback(function ($percent, $stage) use (&$progressUpdates) {
                $progressUpdates[] = [
                    'progress' => $percent,
                    'stage' => $stage,
                ];
            });

            // Generate report
            $report = $service->aggregateReport($startDate, $endDate);

            // Store in session for CSV export with the specific date range
            session([
                "report:{$company->id}:{$startDate}:{$endDate}" => $report,
                "report_time:{$company->id}:{$startDate}:{$endDate}" => now(),
            ]);

            // Cache the latest report summary for the AI chatbot (expires in 24 hours)
            try {
                cache(["company:{$company->id}:latest_report_summary" => $report], now()->addHours(24));
            } catch (\Exception $e) {
                Log::warning('Failed to cache latest report summary for AI chatbot', ['error' => $e->getMessage()]);
            }

            Log::info('Report data fetched successfully', [
                'company_id' => $company->id,
                'total_leads' => $report['total_leads'] ?? 0,
                'total_activities' => $report['total_activities'] ?? 0,
                'listing_refs_count' => count($report['leads_by_listing_ref'] ?? []),
            ]);

            return response()->json([
                'success' => true,
                'data' => $report,
                'progress_updates' => $progressUpdates,
            ]);
        } catch (\Exception $e) {
            Log::error('Report fetch error', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed Property Reference analytics
     * Returns unique property references and lead counts with percentages
     * 
     * AJAX endpoint: GET /report/{company}/property-references
     */
    public function propertyReferenceAnalytics(Request $request, Company $company)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date, 'Asia/Dubai')->startOfDay()->format('Y-m-d H:i:s');
        $endDate = Carbon::parse($request->end_date, 'Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s');

        try {
            $reportService = new ReportService($company);
            $analyzer = new PropertyReferenceAnalyzer($company);

            // Find the Property Reference field
            $propertyRefField = $reportService->getListingReferenceFieldId();
            
            if (!$propertyRefField) {
                return response()->json([
                    'success' => false,
                    'error' => 'Property Reference field not found in this Bitrix24 instance',
                ], 404);
            }

            // Fetch leads
            $leads = $reportService->fetchLeads($startDate, $endDate);

            // Get analytics
            $analytics = $analyzer->getPropertyReferenceAnalytics($leads, $propertyRefField);

            Log::info('Property Reference analytics retrieved', [
                'company_id' => $company->id,
                'field_id' => $propertyRefField,
                'unique_properties' => $analytics['unique_properties'],
                'total_leads' => $analytics['total_leads'],
            ]);

            return response()->json([
                'success' => true,
                'field_id' => $propertyRefField,
                'analytics' => $analytics,
                'date_range' => [
                    'start' => $request->start_date,
                    'end' => $request->end_date,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Property Reference analytics error', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear cached report data.
     */
    public function clearCache(Request $request, Company $company)
    {
        try {
            $service = new ReportService($company);
            $service->clearCache();

            // Also clear property reference field cache
            $analyzer = new PropertyReferenceAnalyzer($company);
            $analyzer->clearFieldCache();

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Cache cleared successfully.']);
            }

            return back()->with('success', 'Report cache cleared for ' . $company->name);
        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }

            return back()->with('error', 'Failed to clear cache: ' . $e->getMessage());
        }
    }

    /**
     * Export the report as CSV.
     * Enhanced with Property Reference breakdown.
     * Uses cached data from session if available, otherwise fetches fresh.
     */
    public function exportCsv(Request $request, Company $company)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date, 'Asia/Dubai')->startOfDay()->format('Y-m-d H:i:s');
        $endDate = Carbon::parse($request->end_date, 'Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s');

        try {
            // Prefer report payload sent from the UI (the currently viewed data)
            $report = null;
            $payload = $request->input('report');
            if ($payload) {
                if (is_string($payload)) {
                    $decoded = json_decode($payload, true);
                } else {
                    $decoded = $payload;
                }

                if (is_array($decoded) && !empty($decoded)) {
                    $report = $decoded;
                }
            }

            // Fallback to session-cached report for the exact date range
            if (!$report) {
                $report = session("report:{$company->id}:{$startDate}:{$endDate}");
            }

            // As last resort, regenerate (this is the slow path)
            if (!$report) {
                $service = new ReportService($company);
                $report = $service->aggregateReport($startDate, $endDate);
            }

            $filename = "{$company->name}_report_{$request->start_date}_to_{$request->end_date}.csv";

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($report, $company) {
                $file = fopen('php://output', 'w');

                // ========== REPORT SUMMARY ==========
                fputcsv($file, ['=== REPORT SUMMARY ===']);
                fputcsv($file, ['Generated At', $report['generated_at']]);
                fputcsv($file, ['Period', $report['start_date'] . ' to ' . $report['end_date']]);
                fputcsv($file, ['Total Leads', $report['total_leads']]);
                fputcsv($file, ['Total Activities', $report['total_activities']]);
                fputcsv($file, []);

                // ========== LEADS BY SOURCE ==========
                fputcsv($file, ['=== LEADS BY SOURCE ===']);
                fputcsv($file, ['Source', 'Count', 'Percentage']);
                $totalLeads = $report['total_leads'] ?? 0;
                foreach ($report['leads_by_source'] as $source => $count) {
                    $pct = $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0;
                    fputcsv($file, [$source, $count, $pct . '%']);
                }
                fputcsv($file, []);

                // ========== LEADS BY PROPERTY REFERENCE ==========
                if (!empty($report['leads_by_listing_ref'])) {
                    fputcsv($file, ['=== LEADS BY PROPERTY REFERENCE ===']);
                    fputcsv($file, ['Property Reference', 'Lead Count', 'Percentage']);
                    
                    $emptyCount = 0;
                    $uniqueCount = 0;
                    foreach ($report['leads_by_listing_ref'] as $ref => $count) {
                        $pct = $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0;
                        fputcsv($file, [$ref, $count, $pct . '%']);
                        
                        if ($ref === '(Empty)') {
                            $emptyCount = $count;
                        } else {
                            $uniqueCount++;
                        }
                    }
                    
                    fputcsv($file, []);
                    fputcsv($file, ['Summary Statistics for Property References']);
                    fputcsv($file, ['Total Unique Properties', $uniqueCount]);
                    fputcsv($file, ['Properties with Leads', count($report['leads_by_listing_ref']) - ($emptyCount > 0 ? 1 : 0)]);
                    fputcsv($file, ['Leads without Reference', $emptyCount]);
                    fputcsv($file, []);
                }

                // ========== ACTIVITIES BY TYPE ==========
                fputcsv($file, ['=== ACTIVITIES BY TYPE ===']);
                fputcsv($file, ['Activity Type', 'Count', 'Percentage']);
                $totalActivities = $report['total_activities'] ?? 0;
                foreach ($report['activities_by_type'] as $type => $count) {
                    $pct = $totalActivities > 0 ? round(($count / $totalActivities) * 100, 1) : 0;
                    fputcsv($file, [$type, $count, $pct . '%']);
                }
                fputcsv($file, []);

                // ========== USER ANALYTICS ==========
                if (!empty($report['user_analytics'])) {
                    fputcsv($file, ['=== USER ANALYTICS ===']);
                    fputcsv($file, ['User', 'Total Leads', 'Total Activities', 'Activity Breakdown']);
                    foreach ($report['user_analytics'] as $user) {
                        $actSummary = [];
                        foreach ($user['activities_by_type'] as $type => $count) {
                            $actSummary[] = "{$type}: {$count}";
                        }
                        fputcsv($file, [
                            $user['user_name'] ?? "User #{$user['user_id']}",
                            $user['total_leads'],
                            $user['total_activities'],
                            implode('; ', $actSummary),
                        ]);
                    }
                }

                fclose($file);
            };

            Log::info('CSV export started', [
                'company_id' => $company->id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            return Response::stream($callback, 200, $headers);

        } catch (\Exception $e) {
            Log::error('CSV export error', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Failed to export: ' . $e->getMessage());
        }
    }

    /**
     * Detect if the page is being opened from within Bitrix24 iframe.
     * This is indicated by the X-Bitrix-Request header or by query parameters from Bitrix24.
     */
    private function isBitrix24Context(Request $request): bool
    {
        // Check for X-Bitrix-Request header (sent by Bitrix24 iframe)
        if ($request->header('X-Bitrix-Request')) {
            return true;
        }

        // Check for common Bitrix24 query parameters
        if ($request->has('APP_SID') || $request->has('DOMAIN') || $request->has('PLACEMENT')) {
            return true;
        }

        // Check if Referer header indicates Bitrix24
        $referer = $request->header('Referer', '');
        if (strpos($referer, 'bitrix24.com') !== false || strpos($referer, 'bitrix24.ru') !== false) {
            return true;
        }

        return false;
    }
}