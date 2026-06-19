<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class BitrixCacheService
{
    /**
     * Retrieve cached Bitrix query result or compute it.
     *
     * @param  int      $companyId
     * @param  string   $query
     * @param  callable $callback   Function that performs the actual Bitrix request.
     * @param  int      $ttlMinutes Cache time‑to‑live in minutes (default 1).
     * @return mixed
     */
    public function remember(int $companyId, string $query, callable $callback, int $ttlMinutes = 1)
    {
        $cacheKey = "company_{$companyId}_bitrix_" . md5($query);
        return Cache::remember($cacheKey, now()->addMinutes($ttlMinutes), $callback);
    }
}
?>
