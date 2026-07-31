<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeTargetHit implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $symbol;
    public string $type; // BUY or SELL
    public string $target; // TP1, TP2, TP3, SL
    public float $profit; // PnL percentage or amount
    public string $message; // Ready-made notification text

    public function __construct(string $symbol, string $type, string $target, float $profit)
    {
        $this->symbol = $symbol;
        $this->type = $type;
        $this->target = $target;
        $this->profit = round($profit, 2);
        
        $actionText = $type === 'BUY' ? 'LONG' : 'SHORT';
        $targetText = strtoupper($target);
        
        if ($target === 'sl') {
            $this->message = "🚨 {$symbol} {$actionText} hit Stop Loss! ({$this->profit}%)";
        } else {
            $this->message = "🎯 {$symbol} {$actionText} hit {$targetText}! Profit: {$this->profit}%";
        }
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('trades'),
        ];
    }
}
