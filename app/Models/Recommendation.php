<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model {
    protected $fillable = [
        'coin_id', 'analysis_id', 'timeframe', 'action', 'entry_price',
        'tp1', 'tp2', 'tp3', 'sl', 'risk_reward', 'confidence', 'reasoning',
        'confluences', 'invalidation', 'sentiment_score', 'whale_activity', 'liquidity_data',
        'ai_model', 'strategy', 'mtf_mode', 'mtf_timeframes', 'status', 'expires_at'
    ];
    protected $casts = [
        'entry_price' => 'float', 'tp1' => 'float', 'tp2' => 'float',
        'tp3' => 'float', 'sl' => 'float', 'confidence' => 'integer',
        'confluences' => 'array', 'whale_activity' => 'array',
        'liquidity_data' => 'array', 'sentiment_score' => 'float',
        'mtf_mode' => 'boolean', 'expires_at' => 'datetime'
    ];
    public function coin() { return $this->belongsTo(Coin::class); }
    public function analysis() { return $this->belongsTo(Analysis::class); }
    public function paperTrades() { return $this->hasMany(PaperTrade::class); }
    public function isActive(): bool { return $this->status === 'active'; }
}
