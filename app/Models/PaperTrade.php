<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PaperTrade extends Model {
    protected $fillable = [
        'session_id', 'coin_id', 'recommendation_id', 'type', 'entry_price',
        'exit_price', 'quantity', 'tp1', 'tp2', 'tp3', 'sl', 'custom_tp_percent', 'pnl',
        'pnl_percent', 'status', 'opened_at', 'closed_at', 'close_target', 'history', 'highest_target_hit', 'hindsight_result'
    ];
    protected $casts = [
        'entry_price' => 'float', 'exit_price' => 'float', 'quantity' => 'float',
        'tp1' => 'float', 'tp2' => 'float', 'tp3' => 'float', 'sl' => 'float',
        'custom_tp_percent' => 'float',
        'pnl' => 'float', 'pnl_percent' => 'float',
        'opened_at' => 'datetime', 'closed_at' => 'datetime', 'history' => 'array', 'hindsight_result' => 'array'
    ];
    public function session() { return $this->belongsTo(PaperTradingSession::class, 'session_id'); }
    public function coin() { return $this->belongsTo(Coin::class); }
    public function recommendation() { return $this->belongsTo(Recommendation::class); }
}
