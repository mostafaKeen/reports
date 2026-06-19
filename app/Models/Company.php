<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'client_id',
        'client_secret',
        'member_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'is_active',
        'bitrix_api_key',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Check if the company is connected to Bitrix24.
     */
    public function isConnected(): bool
    {
        return !empty($this->access_token) && !empty($this->refresh_token);
    }

    /**
     * Check if the access token is expired or close to expiring (within 5 minutes).
     */
    public function isTokenExpired(): bool
    {
        if (!$this->expires_at) {
            return true;
        }
        return $this->expires_at->subMinutes(5)->isPast();
    }
}
