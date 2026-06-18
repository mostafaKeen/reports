<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BitrixClient
{
    protected Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    /**
     * Call a Bitrix24 REST API method.
     * Auth token is sent as a query parameter, not in the request body.
     */
    public function call(string $method, array $params = []): array
    {
        if (!$this->company->is_active) {
            throw new Exception("Bitrix24 API calls are disabled for deactivated company: {$this->company->name}");
        }

        $this->ensureValidToken();

        // Build endpoint with auth token as query parameter
        $endpoint = "https://{$this->company->domain}/rest/{$method}.json";
        
        $response = Http::post($endpoint, $params, [
            'auth' => [$this->company->access_token, ''], // This sends auth as Bearer token
        ]);

        // Alternative approach if the above doesn't work:
        // $queryParams = array_merge($params, ['auth' => $this->company->access_token]);
        // $response = Http::post($endpoint . '?' . http_build_query(['auth' => $this->company->access_token]), $params);

        if ($response->failed()) {
            $statusCode = $response->status();
            $body = $response->body();
            
            Log::error("Bitrix24 API Error", [
                'company_id' => $this->company->id,
                'method' => $method,
                'status' => $statusCode,
                'body' => $body
            ]);

            // If 401 (Unauthorized), try to refresh token
            if ($statusCode === 401) {
                Log::warning("Got 401 from Bitrix24, attempting token refresh", [
                    'company_id' => $this->company->id
                ]);
                $this->refreshToken();
                // Retry the request with new token
                return $this->call($method, $params);
            }

            throw new Exception("Bitrix24 API call failed: " . $body);
        }

        $responseData = $response->json();

        // Check for Bitrix24 error in response
        if (isset($responseData['error']) && $responseData['error']) {
            Log::error("Bitrix24 API returned error", [
                'company_id' => $this->company->id,
                'method' => $method,
                'error' => $responseData['error'],
                'error_description' => $responseData['error_description'] ?? null
            ]);
            throw new Exception("Bitrix24 API error: " . ($responseData['error_description'] ?? $responseData['error']));
        }

        return $responseData;
    }

    /**
     * Ensure the access token is valid, refreshing it if expired.
     */
    public function ensureValidToken(): void
    {
        if ($this->company->isTokenExpired()) {
            $this->refreshToken();
        }
    }

    /**
     * Refresh the OAuth 2.0 access token.
     */
    public function refreshToken(): void
    {
        if (!$this->company->is_active) {
            throw new Exception("Token refresh is disabled for deactivated company: {$this->company->name}");
        }

        if (empty($this->company->refresh_token)) {
            throw new Exception("No refresh token available for company ID: {$this->company->id}");
        }

        Log::info("Refreshing Bitrix24 token for company", ['id' => $this->company->id]);

        $response = Http::asForm()->post('https://oauth.bitrix.info/oauth/token/', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->company->client_id,
            'client_secret' => $this->company->client_secret,
            'refresh_token' => $this->company->refresh_token,
        ]);

        if ($response->failed()) {
            Log::error("Failed to refresh Bitrix24 token", [
                'company_id' => $this->company->id,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception("Token refresh failed: " . $response->body());
        }

        $data = $response->json();

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new Exception("Token refresh response did not contain expected tokens");
        }

        $this->company->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ]);

        Log::info("Bitrix24 token refreshed successfully", ['id' => $this->company->id]);
    }
}