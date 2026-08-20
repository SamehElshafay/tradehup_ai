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
    public string $type; // 'info', 'success', 'warning', 'error'
    public ?string $symbol; // the coin this progress line is about
    public ?string $action; // 'BUY', 'SELL', 'WAIT', 'ERROR'
    public ?int $analysis_id; // the DB id of the analysis
    public ?string $error_reason; // full error string if failed
    public ?string $strategy; // the strategy used to find the coin
    public ?array $coins_list; // full list of coins being scanned (sent once on start)
    public ?int $raw_binance_count; // total USDT tickers fetched from Binance before strategy filters

    public function __construct(
        string $message,
        string $type = 'info',
        ?string $symbol = null,
        ?string $action = null,
        ?int $analysisId = null,
        ?string $errorReason = null,
        ?string $strategy = null,
        ?array $coinsList = null,
        ?int $rawBinanceCount = null
    ) {
        $this->message = $message;
        $this->type = $type;
        $this->symbol = $symbol;
        $this->action = $action;
        $this->analysis_id = $analysisId;
        $this->error_reason = $errorReason;
        $this->strategy = $strategy;
        $this->coins_list = $coinsList;
        $this->raw_binance_count = $rawBinanceCount;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('opportunities'),
        ];
    }
}
