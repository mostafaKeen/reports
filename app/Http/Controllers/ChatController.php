<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Services\ReportService;
use Carbon\Carbon;

class ChatController extends Controller
{
    /**
     * Handle incoming chat message and return AI response
     * 
     * POST /crm-chat
     * {
     *   "question": "What are my top lead sources?",
     *   "company_id": 1 (optional, will use company from context)
     * }
     */
    public function sendMessage(Request $request)
    {
        try {
            // Validate input
            $validated = $request->validate([
                'question' => 'required|string|max:2000|min:3',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            // Get company context
            $company = $this->getCompany($request, $validated);

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unable to determine company context.',
                ], 400);
            }

            // Verify Gemini API key is configured
            if (!$this->validateCompanyAiConfig($company)) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI service not configured for this company. Please contact administrator.',
                ], 400);
            }

            // Get the Gemini service
            $gemini = new GeminiService($company->bitrix_api_key);

            // Build context for better responses
            $context = $this->buildContext($company, $request);

            // Generate response
            $answer = $gemini->generateResponse(
                trim($validated['question']),
                $context
            );

            // Log interaction
            Log::info('CRM Chat - Message processed', [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'question_length' => strlen($validated['question']),
                'response_length' => strlen($answer),
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            // Record activity (safe - column may not exist)
            try {
                $company->recordActivity();
            } catch (\Exception $e) {
                // Silently ignore if last_activity_at column doesn't exist
            }

            return response()->json([
                'success' => true,
                'answer' => $answer,
                'company' => $company->name,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?: 'Invalid input.',
            ], 422);
        } catch (\Exception $e) {
            Log::error('Chat controller error', [
                'error' => $e->getMessage(),
                'question' => substr($request->input('question', ''), 0, 100),
                'user_id' => auth()->id(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'success' => false,
                'error' => $this->getErrorMessage($e),
            ], 500);
        }
    }

    /**
     * Analyze report data and get AI insights
     * 
     * POST /crm-chat/analyze
     * {
     *   "company_id": 1,
     *   "data_type": "leads|activities|all",
     *   "report_data": { ... }
     * }
     */
    public function analyzeReport(Request $request)
    {
        try {
            $validated = $request->validate([
                'company_id' => 'required|integer|exists:companies,id',
                'data_type' => 'required|in:leads,activities,users,all',
                'report_data' => 'nullable|array',
            ]);

            $company = Company::findOrFail($validated['company_id']);

            // Verify AI config
            if (!$this->validateCompanyAiConfig($company)) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI service not configured.',
                ], 400);
            }

            $gemini = new GeminiService($company->bitrix_api_key);

            // Analyze the data
            $analysis = $gemini->analyzeData(
                $validated['data_type'],
                $validated['report_data'] ?? []
            );

            Log::info('CRM Chat - Report analyzed', [
                'company_id' => $company->id,
                'data_type' => $validated['data_type'],
                'user_id' => auth()->id(),
            ]);

            // Record activity (safe)
            try {
                $company->recordActivity();
            } catch (\Exception $e) {
                // Silently ignore
            }

            return response()->json([
                'success' => true,
                'analysis' => $analysis,
                'data_type' => $validated['data_type'],
            ]);

        } catch (\Exception $e) {
            Log::error('Report analysis failed', [
                'error' => $e->getMessage(),
                'company_id' => $validated['company_id'] ?? null,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to analyze report data.',
            ], 500);
        }
    }

    /**
     * Get company from request context
     */
    private function getCompany(Request $request, array $validated): ?Company
    {
        // Use provided company_id
        if (!empty($validated['company_id'])) {
            return Company::find($validated['company_id']);
        }

        // Try to get from query parameter
        if ($request->has('company_id')) {
            return Company::find($request->integer('company_id'));
        }

        // If authenticated user has company association
        if (auth()->check() && auth()->user()->company_id) {
            return Company::find(auth()->user()->company_id);
        }

        // Try to infer from session or route
        $companyId = session('current_company_id');
        if ($companyId) {
            return Company::find($companyId);
        }

        return null;
    }

    /**
     * Validate company has AI configured
     */
    private function validateCompanyAiConfig(Company $company): bool
    {
        return !empty($company->bitrix_api_key) && 
               GeminiService::validateApiKey($company->bitrix_api_key);
    }

    /**
     * Build context for AI responses
     */
    private function buildContext(Company $company, Request $request): array
    {
        $context = [
            'company_name' => $company->name,
            'company_domain' => $company->domain,
            'timestamp' => now()->toIso8601String(),
            'timezone' => config('app.timezone', 'UTC'),
        ];

        try {
            $reportService = new ReportService($company);
            
            // Fetch recent 30 days of leads and activities
            $startDate = Carbon::now('Asia/Dubai')->subDays(30)->startOfDay()->format('Y-m-d H:i:s');
            $endDate = Carbon::now('Asia/Dubai')->endOfDay()->format('Y-m-d H:i:s');

            $leads = $reportService->fetchLeads($startDate, $endDate);
            
            // Sort leads descending by creation date to get latest leads first
            usort($leads, function ($a, $b) {
                return strcmp($b['DATE_CREATE'] ?? '', $a['DATE_CREATE'] ?? '');
            });

            // Compact the leads to save token space in context
            $recentLeads = array_map(function ($lead) {
                return [
                    'id' => $lead['ID'] ?? null,
                    'title' => $lead['TITLE'] ?? null,
                    'date_create' => $lead['DATE_CREATE'] ?? null,
                    'source' => $lead['SOURCE_ID'] ?? null,
                    'status' => $lead['STATUS_ID'] ?? null,
                    'assigned_by' => $lead['ASSIGNED_BY_ID'] ?? null,
                ];
            }, array_slice($leads, 0, 15));

            $context['recent_leads'] = $recentLeads;

            // Fetch lead sources mapping
            $sources = $reportService->fetchLeadSources();
            $context['lead_sources'] = $sources;

            // Also get report summary
            $reportSummary = $reportService->aggregateReport($startDate, $endDate);
            unset($reportSummary['user_analytics']); // Remove user breakdown to fit token limit
            $context['report_summary'] = $reportSummary;

        } catch (\Exception $e) {
            Log::warning('Failed to fetch Bitrix24 context for AI Chat', [
                'company_id' => $company->id,
                'error' => $e->getMessage()
            ]);
        }

        // Add report data from request if provided
        if ($request->has('report_data')) {
            $reportData = $request->input('report_data');
            if (is_array($reportData) && !empty($reportData)) {
                $context['report_data'] = array_slice($reportData, 0, 5); // Limit context size
            }
        }

        return $context;
    }

    private function getErrorMessage(\Exception $e): string
    {
        $message = $e->getMessage();

        if (strpos($message, '429') !== false) {
            return 'AI rate limit exceeded (429). Google free-tier API keys are limited to 15 requests per minute. Please wait a moment before trying again, or upgrade to a pay-as-you-go key in your company settings.';
        }

        // Hide technical details in production
        if (!config('app.debug')) {
            if (strpos($message, 'Gemini API') !== false) {
                return 'AI service is temporarily unavailable. Please try again.';
            }
            if (strpos($message, 'API key') !== false) {
                return 'AI service configuration error. Please contact support.';
            }
        }

        return $message ?: 'Failed to process your request. Please try again.';
    }
}