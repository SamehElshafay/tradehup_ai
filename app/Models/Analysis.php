<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Analysis extends Model {
    protected $fillable = [
        'coin_id', 'timeframe', 'raw_data', 'classical_data', 'smc_data',
        'harmonic_data', 'volume_profile_data', 'chart_overlays',
        'overall_bias', 'overall_confidence', 'confluences', 'analyzed_at'
    ];
    protected $casts = [
        'raw_data' => 'array', 'classical_data' => 'array', 'smc_data' => 'array',
        'harmonic_data' => 'array', 'volume_profile_data' => 'array',
        'chart_overlays' => 'array', 'confluences' => 'array',
        'analyzed_at' => 'datetime'
    ];
    public function coin() { return $this->belongsTo(Coin::class); }
    public function recommendations() { return $this->hasMany(Recommendation::class); }
}
