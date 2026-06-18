<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BitrixRateLimiter
{
    protected $company;
    protected $defaultDelayMs = 500;      // Default 2 requests per second
    protected $initialBackoffMs = 1000;   // First rate limit backoff is 1 second
    protected $maxBackoffMs = 8000;       // Maximum backoff of 8 seconds
    protected $backoffMultiplier = 2;     // Exponential backoff multiplier

    public function __construct($company)
    {
        $this->company = $company;
    }

    /**
     * Wait if necessary to respect rate limits.
     * Returns the delay applied in milliseconds.
     */
    public function waitIfNeeded(): int
    {
        $cacheKey = $this->getCacheKey();
        $lastRequestTime = Cache::get($cacheKey);
        $now = microtime(true) * 1000; // Convert to milliseconds

        if ($lastRequestTime === null) {
            // First request
            Cache::put($cacheKey, $now, 60); // Store for 60 seconds
            return 0;
        }

        $timeSinceLastRequest = $now - $lastRequestTime;
        $requiredDelay = $this->getRequiredDelay();

        if ($timeSinceLastRequest < $requiredDelay) {
            $delayMs = (int)($requiredDelay - $timeSinceLastRequest);
            
            Log::debug("Rate limiting: Sleeping for {$delayMs}ms", [
                'company_id' => $this->company->id,
                'time_since_last' => $timeSinceLastRequest,
                'required_delay' => $requiredDelay,
            ]);

            // Sleep in milliseconds
            usleep($delayMs * 1000);
        }

        // Update last request time
        Cache::put($cacheKey, microtime(true) * 1000, 60);
        
        return max(0, (int)($requiredDelay - $timeSinceLastRequest));
    }

    /**
     * Register a rate limit error (503, 429) and adjust delay.
     * Implements exponential backoff.
     */
    public function registerRateLimitError(): void
    {
        $backoffKey = $this->getBackoffKey();
        $currentBackoff = Cache::get($backoffKey, 0);
        
        // Increase backoff exponentially, starting at 1 second and capping at 8 seconds
        $newBackoff = min(
            $this->maxBackoffMs,
            $currentBackoff === 0 ? $this->initialBackoffMs : (int)($currentBackoff * $this->backoffMultiplier)
        );

        Cache::put($backoffKey, $newBackoff, 300); // Store for 5 minutes

        Log::warning("Rate limit error detected, increasing backoff", [
            'company_id' => $this->company->id,
            'previous_backoff' => $currentBackoff,
            'new_backoff' => $newBackoff,
        ]);
    }

    /**
     * Reset backoff after successful requests.
     */
    public function resetBackoff(): void
    {
        $backoffKey = $this->getBackoffKey();
        Cache::forget($backoffKey);
        
        Log::debug("Rate limiter backoff reset", [
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * Get the required delay between requests in milliseconds.
     */
    protected function getRequiredDelay(): float
    {
        $backoffKey = $this->getBackoffKey();
        $backoff = Cache::get($backoffKey, 0);
        
        return $backoff > 0 ? max($this->defaultDelayMs, $backoff) : $this->defaultDelayMs;
    }

    /**
     * Get cache key for last request time.
     */
    protected function getCacheKey(): string
    {
        return "bitrix_rate_limit:company_{$this->company->id}:last_request";
    }

    /**
     * Get cache key for backoff multiplier.
     */
    protected function getBackoffKey(): string
    {
        return "bitrix_rate_limit:company_{$this->company->id}:backoff";
    }
}