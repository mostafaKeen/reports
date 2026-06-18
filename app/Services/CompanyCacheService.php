<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class CompanyCacheService
{
    protected $company;
    protected $prefix = 'company:';

    public function __construct($company)
    {
        $this->company = $company;
        $this->prefix .= $company->id . ':report:';
    }

    /**
     * Get a value from cache.
     * Returns null if key doesn't exist or Redis fails.
     */
    public function get(string $key)
    {
        try {
            $fullKey = $this->prefix . $key;
            $value = Redis::get($fullKey);

            if ($value !== null) {
                return json_decode($value, true);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('Cache get failed, returning null', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Set a value in cache with optional TTL.
     * Silently fails if Redis is unavailable.
     */
    public function set(string $key, $value, int $ttl = 300): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            $encoded = json_encode($value);

            if ($ttl > 0) {
                Redis::setex($fullKey, $ttl, $encoded);
            } else {
                Redis::set($fullKey, $encoded);
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache set failed, continuing without cache', [
                'key' => $key,
                'ttl' => $ttl,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete a value from cache.
     */
    public function delete(string $key): bool
    {
        try {
            $fullKey = $this->prefix . $key;
            Redis::del($fullKey);
            return true;
        } catch (\Exception $e) {
            Log::warning('Cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all report cache for this company.
     */
    public function flush(): bool
    {
        try {
            $pattern = $this->prefix . '*';
            $iterator = null;

            do {
                $keys = Redis::scan($iterator, [
                    'MATCH' => $pattern,
                    'COUNT' => 100,
                ]);

                if ($keys && is_array($keys[1]) && count($keys[1]) > 0) {
                    Redis::del(...$keys[1]);
                }

                $iterator = $keys[0] ?? null;
            } while ($iterator && $iterator !== '0' && $iterator !== 0);

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache flush failed', [
                'company_id' => $this->company->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}