<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BitrixClient
{
    protected Company $company;
    protected int $maxRetries = 2;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    /**
     * Call a Bitrix24 REST API method.
     * Auth token must be passed as query parameter or in request body.
     */
    public function call(string $method, array $params = [], int $retryCount = 0): array
    {
        if (!$this->company->is_active) {
            throw new Exception("Bitrix24 API calls are disabled for deactivated company: {$this->company->name}");
        }

        $this->ensureValidToken();

        // Build the endpoint
        $endpoint = "https://{$this->company->domain}/rest/{$method}.json";

        // Add auth token to params
        $params['auth'] = $this->company->access_token;

        try {
            // Use asForm() to properly send params as form data
            $response = Http::asForm()->timeout(30)->post($endpoint, $params);

            if ($response->failed()) {
                $statusCode = $response->status();
                $body = $response->body();
                $jsonBody = $response->json();

                Log::error("Bitrix24 API Error", [
                    'company_id' => $this->company->id,
                    'method' => $method,
                    'status' => $statusCode,
                    'body' => $body,
                    'json' => $jsonBody,
                ]);

                // If 401, token expired - refresh and retry
                if ($statusCode === 401 && $retryCount < $this->maxRetries) {
                    Log::warning("Got 401 Unauthorized from Bitrix24, refreshing token and retrying", [
                        'company_id' => $this->company->id,
                        'attempt' => $retryCount + 1
                    ]);
                    $this->refreshToken();
                    return $this->call($method, $params, $retryCount + 1);
                }

                throw new Exception("Bitrix24 API call failed (HTTP {$statusCode}): " . ($jsonBody['error_description'] ?? $body));
            }

            $responseData = $response->json();

            // Check for Bitrix24 error in response
            if (isset($responseData['error']) && $responseData['error']) {
                $errorMsg = $responseData['error_description'] ?? $responseData['error'];
                
                Log::warning("Bitrix24 API returned error response", [
                    'company_id' => $this->company->id,
                    'method' => $method,
                    'error' => $responseData['error'],
                    'error_description' => $errorMsg
                ]);

                // If NOAUTH, token might be invalid - try refresh once
                if (stripos($responseData['error'], 'NOAUTH') !== false && $retryCount < $this->maxRetries) {
                    Log::info("Got NOAUTH error, attempting token refresh", [
                        'company_id' => $this->company->id
                    ]);
                    $this->refreshToken();
                    return $this->call($method, $params, $retryCount + 1);
                }

                throw new Exception("Bitrix24 API error: {$errorMsg}");
            }

            return $responseData;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Bitrix24 HTTP Request Exception", [
                'company_id' => $this->company->id,
                'method' => $method,
                'error' => $e->getMessage(),
                'response' => $e->response?->body()
            ]);
            throw new Exception("Bitrix24 request failed: " . $e->getMessage());
        }
    }

    /**
     * Ensure the access token is valid, refreshing it if expired.
     */
    public function ensureValidToken(): void
    {
        // Check if token needs refresh
        if ($this->company->expires_at && now()->isAfter($this->company->expires_at)) {
            Log::info("Token expired, refreshing", ['company_id' => $this->company->id]);
            $this->refreshToken();
        }
    }

    /**
     * Refresh the OAuth 2.0 access token using refresh_token.
     */
    public function refreshToken(): void
    {
        if (!$this->company->is_active) {
            throw new Exception("Token refresh is disabled for deactivated company: {$this->company->name}");
        }

        if (empty($this->company->refresh_token)) {
            throw new Exception("No refresh token available for company: {$this->company->name}. Please reconnect.");
        }

        Log::info("Refreshing Bitrix24 OAuth token", [
            'company_id' => $this->company->id,
            'domain' => $this->company->domain
        ]);

        try {
            $response = Http::asForm()->timeout(30)->post('https://oauth.bitrix.info/oauth/token/', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->company->client_id,
                'client_secret' => $this->company->client_secret,
                'refresh_token' => $this->company->refresh_token,
            ]);

            if ($response->failed()) {
                $error = $response->json();
                Log::error("Failed to refresh Bitrix24 token", [
                    'company_id' => $this->company->id,
                    'status' => $response->status(),
                    'error' => $error['error'] ?? $response->body()
                ]);
                throw new Exception("Token refresh failed: " . ($error['error_description'] ?? $response->body()));
            }

            $data = $response->json();

            if (empty($data['access_token']) || empty($data['refresh_token'])) {
                Log::error("Invalid token refresh response", [
                    'company_id' => $this->company->id,
                    'response_keys' => array_keys($data)
                ]);
                throw new Exception("Token refresh response missing access_token or refresh_token");
            }

            // Update company with new tokens
            $this->company->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            ]);

            Log::info("Bitrix24 token refreshed successfully", [
                'company_id' => $this->company->id,
                'expires_in' => $data['expires_in'] ?? 3600
            ]);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Token refresh request failed", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Token refresh request failed: " . $e->getMessage());
        }
    }
}