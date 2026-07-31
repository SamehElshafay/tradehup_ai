<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketScanProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $message;
    public string $type; // 'info', 'success', 'warning'
    public ?string $symbol; // the coin this progress line is about, null for general scan-level messages

    public function __construct(string $message, string $type = 'info', ?string $symbol = null)
    {
        $this->message = $message;
        $this->type = $type;
        $this->symbol = $symbol;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('opportunities'),
        ];
    }
}
