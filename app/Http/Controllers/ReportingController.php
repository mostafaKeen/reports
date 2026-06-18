<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;

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

        // Get all active companies for the dropdown
        $companies = Company::where('is_active', true)->get();

        return view('reports.index', [
            'company' => $company,
            'companies' => $companies,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Fetch report data (AJAX endpoint).
     */
    public function fetchData(Request $request, Company $company)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date, 'Asia/Dubai')->startOfDay()->toIso8601String();
        $endDate = Carbon::parse($request->end_date, 'Asia/Dubai')->endOfDay()->toIso8601String();

        try {
            $service = new ReportService($company);
            $report = $service->aggregateReport($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
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
     */
    public function exportCsv(Request $request, Company $company)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->start_date, 'Asia/Dubai')->startOfDay()->toIso8601String();
        $endDate = Carbon::parse($request->end_date, 'Asia/Dubai')->endOfDay()->toIso8601String();

        $service = new ReportService($company);
        $report = $service->aggregateReport($startDate, $endDate);

        $filename = "{$company->name}_report_{$request->start_date}_to_{$request->end_date}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($report) {
            $file = fopen('php://output', 'w');

            // Summary Section
            fputcsv($file, ['=== REPORT SUMMARY ===']);
            fputcsv($file, ['Generated At', $report['generated_at']]);
            fputcsv($file, ['Period', $report['start_date'] . ' to ' . $report['end_date']]);
            fputcsv($file, ['Total Leads', $report['total_leads']]);
            fputcsv($file, ['Total Activities', $report['total_activities']]);
            fputcsv($file, []);

            // Leads by Source
            fputcsv($file, ['=== LEADS BY SOURCE ===']);
            fputcsv($file, ['Source', 'Count']);
            foreach ($report['leads_by_source'] as $source => $count) {
                fputcsv($file, [$source, $count]);
            }
            fputcsv($file, []);

            // Leads by Listing Reference
            if (!empty($report['leads_by_listing_ref'])) {
                fputcsv($file, ['=== LEADS BY LISTING REFERENCE ===']);
                fputcsv($file, ['Reference', 'Count']);
                foreach ($report['leads_by_listing_ref'] as $ref => $count) {
                    fputcsv($file, [$ref, $count]);
                }
                fputcsv($file, []);
            }

            // Activities by Type
            fputcsv($file, ['=== ACTIVITIES BY TYPE ===']);
            fputcsv($file, ['Activity Type', 'Count']);
            foreach ($report['activities_by_type'] as $type => $count) {
                fputcsv($file, [$type, $count]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
