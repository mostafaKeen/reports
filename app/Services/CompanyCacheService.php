<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Redis;

class CompanyCacheService
{
    protected Company $company;

    public function __construct(Company $company)
    {
        $this->company = $company;
    }

    /**
     * Helper to prefix the cache key.
     */
    protected function getNamespacedKey(string $key): string
    {
        return "company:{$this->company->id}:{$key}";
    }

    /**
     * Get a value from Redis cache.
     */
    public function get(string $key)
    {
        $cached = Redis::get($this->getNamespacedKey($key));
        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Set a value in Redis cache with an optional TTL (seconds).
     */
    public function set(string $key, $value, int $ttl = 60): void
    {
        Redis::setex($this->getNamespacedKey($key), $ttl, json_encode($value));
    }

    /**
     * Delete a key from Redis cache.
     */
    public function forget(string $key): void
    {
        Redis::del($this->getNamespacedKey($key));
    }

    /**
     * Increment a hash field or key value.
     */
    public function increment(string $key, int $amount = 1): int
    {
        return Redis::incrby($this->getNamespacedKey($key), $amount);
    }
}
