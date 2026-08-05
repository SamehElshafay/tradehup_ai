<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PaperTradingSession extends Model {
    protected $fillable = [
        'user_id', 'initial_balance', 'current_balance', 'target_balance',
        'status', 'win_rate', 'total_trades', 'winning_trades', 'started_at', 'ended_at'
    ];
    protected $casts = [
        'initial_balance' => 'float', 'current_balance' => 'float',
        'target_balance' => 'float', 'win_rate' => 'float',
        'total_trades' => 'integer', 'winning_trades' => 'integer',
        'started_at' => 'datetime', 'ended_at' => 'datetime'
    ];
    public function user() { return $this->belongsTo(User::class); }
    public function trades() { return $this->hasMany(PaperTrade::class, 'session_id'); }
    public function openTrades() { return $this->trades()->where('status', 'open'); }
    public function pnlPercent(): float {
        if ($this->initial_balance == 0) return 0;
        return (($this->current_balance - $this->initial_balance) / $this->initial_balance) * 100;
    }
    public function totalProfit(): float {
        return (float) $this->trades()->where('pnl', '>', 0)->sum('pnl');
    }
    public function totalLoss(): float {
        return (float) $this->trades()->where('pnl', '<', 0)->sum('pnl');
    }
}
