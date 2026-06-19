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
    
    //   protected $hidden = [
    //       'access_token',
    //       'refresh_token',
    //       'client_secret',
    //       'bitrix_api_key',  // Add this line
    //   ];
     

     /**
     * Add this method to your Company class
     * Check if Gemini API is configured
     */
    public function hasGeminiKey(): bool
    {
        return !empty($this->bitrix_api_key) && 
               strlen($this->bitrix_api_key) >= 20;
    }
 
    /**
     * Add this method to your Company class
     * Get masked API key for display purposes (hide sensitive part)
     */
    public function getMaskedApiKey(): string
    {
        if (empty($this->bitrix_api_key)) {
            return 'Not configured';
        }
 
        $key = $this->bitrix_api_key;
        $visibleChars = 8;
        $maskedLength = strlen($key) - $visibleChars;
        
        return str_repeat('*', $maskedLength) . substr($key, -$visibleChars);
    }
 
    /**
     * Add this method to your Company class
     * Record last activity timestamp
     */
    public function recordActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }
 
    /**
     * Add this scope to your Company class
     * Get companies with Gemini API configured
     */
    public function scopeWithGemini($query)
    {
        return $query->where('is_active', true)
                     ->whereNotNull('bitrix_api_key')
                     ->where('bitrix_api_key', '!=', '');
    }

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
