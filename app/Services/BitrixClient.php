<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BitrixClient
{
    protected Company $company;
    protected BitrixRateLimiter $rateLimiter;
    protected int $maxRetries = 3;

    public function __construct(Company $company)
    {
        $this->company = $company;
        $this->rateLimiter = new BitrixRateLimiter($company);
    }

    /**
     * Call a Bitrix24 REST API method with rate limiting.
     */
    public function call(string $method, array $params = [], int $retryCount = 0): array
    {
        if (!$this->company->is_active) {
            throw new Exception("Bitrix24 API calls are disabled for deactivated company: {$this->company->name}");
        }

        $this->ensureValidToken();

        // Apply rate limiting delay
        $delayApplied = $this->rateLimiter->waitIfNeeded();
        if ($delayApplied > 0) {
            Log::debug("Rate limiting applied", [
                'company_id' => $this->company->id,
                'method' => $method,
                'delay_ms' => $delayApplied,
            ]);
        }

        // Build the endpoint
        $endpoint = "https://{$this->company->domain}/rest/{$method}.json";
        $params['auth'] = $this->company->access_token;

        try {
            Log::debug("Bitrix24 API call", [
                'company_id' => $this->company->id,
                'method' => $method,
                'attempt' => $retryCount + 1,
            ]);

            $response = Http::asForm()->timeout(30)->post($endpoint, $params);

            if ($response->failed()) {
                $statusCode = $response->status();
                $body = $response->body();
                $jsonBody = $response->json();

                // Handle rate limiting (429, 503)
                if (($statusCode === 429 || $statusCode === 503) && $retryCount < $this->maxRetries) {
                    $this->rateLimiter->registerRateLimitError();
                    
                    $backoffDelay = $this->getBackoffDelay($retryCount);
                    Log::warning("Rate limited by Bitrix24, retrying with backoff", [
                        'company_id' => $this->company->id,
                        'method' => $method,
                        'status' => $statusCode,
                        'retry' => $retryCount + 1,
                        'backoff_ms' => $backoffDelay,
                    ]);

                    // Sleep with exponential backoff
                    usleep($backoffDelay * 1000);

                    return $this->call($method, $params, $retryCount + 1);
                }

                // Handle 401 (token expired)
                if ($statusCode === 401 && $retryCount < $this->maxRetries) {
                    Log::warning("Got 401 Unauthorized, refreshing token and retrying", [
                        'company_id' => $this->company->id,
                        'method' => $method,
                    ]);
                    $this->refreshToken();
                    return $this->call($method, $params, $retryCount + 1);
                }

                Log::error("Bitrix24 API Error", [
                    'company_id' => $this->company->id,
                    'method' => $method,
                    'status' => $statusCode,
                    'error' => $jsonBody['error_description'] ?? $body,
                ]);

                throw new Exception("Bitrix24 API call failed (HTTP {$statusCode}): " . ($jsonBody['error_description'] ?? $body));
            }

            $responseData = $response->json();

            // Check for Bitrix24 error in response
            if (isset($responseData['error']) && $responseData['error']) {
                $errorMsg = $responseData['error_description'] ?? $responseData['error'];

                // If rate limited or NOAUTH, retry with backoff
                if ((stripos($responseData['error'], 'NOAUTH') !== false || 
                     stripos($responseData['error'], 'too many requests') !== false) && 
                    $retryCount < $this->maxRetries) {
                    
                    if (stripos($responseData['error'], 'NOAUTH') !== false) {
                        Log::info("Got NOAUTH, refreshing token", ['company_id' => $this->company->id]);
                        $this->refreshToken();
                    } else {
                        Log::info("Rate limited in response, backing off", ['company_id' => $this->company->id]);
                        $this->rateLimiter->registerRateLimitError();
                        $backoffDelay = $this->getBackoffDelay($retryCount);
                        usleep($backoffDelay * 1000);
                    }

                    return $this->call($method, $params, $retryCount + 1);
                }

                throw new Exception("Bitrix24 API error: {$errorMsg}");
            }

            // Success - reset backoff
            $this->rateLimiter->resetBackoff();

            Log::debug("Bitrix24 API call successful", [
                'company_id' => $this->company->id,
                'method' => $method,
            ]);

            return $responseData;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Bitrix24 HTTP Request Exception", [
                'company_id' => $this->company->id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            throw new Exception("Bitrix24 request failed: " . $e->getMessage());
        }
    }

    /**
     * Get exponential backoff delay in milliseconds.
     */
    protected function getBackoffDelay(int $retryCount): int
    {
        // Start with 1 second, double each retry: 1s, 2s, 4s, 8s
        $baseDelay = 1000;
        return $baseDelay * pow(2, $retryCount);
    }

    /**
     * Ensure the access token is valid, refreshing it if expired.
     */
    public function ensureValidToken(): void
    {
        if ($this->company->expires_at && now()->isAfter($this->company->expires_at)) {
            Log::info("Token expired, refreshing", ['company_id' => $this->company->id]);
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
                Log::error("Failed to refresh token", [
                    'company_id' => $this->company->id,
                    'status' => $response->status(),
                    'error' => $error['error'] ?? $response->body()
                ]);
                throw new Exception("Token refresh failed: " . ($error['error_description'] ?? $response->body()));
            }

            $data = $response->json();

            if (empty($data['access_token']) || empty($data['refresh_token'])) {
                throw new Exception("Token refresh response missing tokens");
            }

            $this->company->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            ]);

            Log::info("Token refreshed successfully", [
                'company_id' => $this->company->id,
            ]);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error("Token refresh request failed", [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Token refresh failed: " . $e->getMessage());
        }
    }
}