<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol', 'name', 'base_asset', 'quote_asset', 'exchange', 'logo_url',
        'current_price', 'price_change_24h', 'market_cap', 'volume_24h',
        'high_24h', 'low_24h', 'is_active', 'last_synced_at'
    ];

    protected $casts = [
        'current_price'    => 'float',
        'price_change_24h' => 'float',
        'market_cap'       => 'float',
        'volume_24h'       => 'float',
        'high_24h'         => 'float',
        'low_24h'          => 'float',
        'is_active'        => 'boolean',
        'last_synced_at'   => 'datetime',
    ];

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function follows()
    {
        return $this->hasMany(Follow::class);
    }

    public function analyses()
    {
        return $this->hasMany(Analysis::class);
    }

    public function recommendations()
    {
        return $this->hasMany(Recommendation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isFavoritedByUser($userId): bool
    {
        return $this->favorites()->where('user_id', $userId)->exists();
    }

    public function isFollowedByUser($userId): bool
    {
        return $this->follows()->where('user_id', $userId)->exists();
    }
}
