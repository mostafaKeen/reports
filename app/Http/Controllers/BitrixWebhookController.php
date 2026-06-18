<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BitrixWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from Bitrix24.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();
        
        $memberId = $request->input('auth.member_id');
        $event = $request->input('event');
        
        if (!$memberId) {
            Log::warning('Bitrix webhook missing member_id in payload', ['payload' => $payload]);
            return response()->json(['error' => 'Missing member_id'], 400);
        }

        // Find the company associated with this member_id
        $company = Company::where('member_id', $memberId)->first();
        
        if (!$company) {
            Log::warning('Bitrix webhook received for unknown member_id', ['member_id' => $memberId]);
            return response()->json(['error' => 'Unknown tenant'], 404);
        }

        if (!$company->is_active) {
            Log::warning('Bitrix webhook received for deactivated company', ['company' => $company->name]);
            return response()->json(['error' => 'Tenant is deactivated'], 403);
        }

        Log::info("Bitrix webhook received", [
            'company' => $company->name,
            'event' => $event,
            'payload' => $payload
        ]);

        $cache = new CompanyCacheService($company);

        // Process different event types to invalidate or update Redis cache
        switch ($event) {
            case 'ONCRMLEADADD':
                // Increment today's count
                $cache->increment('leads:today:count');
                // Invalidate leads summary/today data to force reload
                $cache->forget('leads:today');
                break;

            case 'ONCRMLEADUPDATE':
                $cache->forget('leads:today');
                $leadId = $request->input('data.FIELDS.ID');
                if ($leadId) {
                    $cache->forget("lead:{$leadId}");
                }
                break;

            case 'ONCRMDEALADD':
            case 'ONCRMDEALUPDATE':
                $cache->forget('pipeline:stage_changes');
                break;

            case 'ONCRMACTIVITYADD':
                $agentId = $request->input('data.FIELDS.AUTHOR_ID');
                if ($agentId) {
                    $cache->increment("agent:{$agentId}:activities:today");
                }
                break;
        }

        return response()->json(['success' => true]);
    }
}
