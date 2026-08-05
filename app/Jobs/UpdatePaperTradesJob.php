<?php
namespace App\Jobs;
use App\Models\PaperTrade;
use App\Services\PythonTAService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Events\TradeTargetHit;

class UpdatePaperTradesJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2;

    public function handle(PythonTAService $taService): void {
        $openTrades = PaperTrade::where('status', 'open')->with(['coin', 'session', 'recommendation'])->get();
        foreach ($openTrades as $trade) {
            try {
                $this->evaluateTradeChronologically($trade);
            } catch (\Exception $e) {
                Log::error('UpdatePaperTradesJob error', ['trade' => $trade->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function evaluateTradeChronologically(PaperTrade $trade): void {
        $symbol = $trade->coin->symbol;
        // Last evaluation time, default to trade creation
        $lastChecked = $trade->updated_at ? $trade->updated_at->timestamp * 1000 : $trade->created_at->timestamp * 1000;
        
        $url = "https://api.binance.com/api/v3/klines";
        $response = \Illuminate\Support\Facades\Http::withoutVerifying()->get($url, [
            'symbol' => $symbol,
            'interval' => '1m',
            'startTime' => $lastChecked - (60 * 1000), // overlap by 1m to not miss
            'limit' => 1000
        ]);

        if (!$response->successful()) return;
        $klines = $response->json();
        if (empty($klines)) return;

        foreach ($klines as $kline) {
            $high = (float) $kline[2];
            $low = (float) $kline[3];
            $close = (float) $kline[4];
            
            // Check SL first in the candle
            $hitSl = $trade->type === 'BUY' ? ($low <= $trade->sl) : ($high >= $trade->sl);
            if ($hitSl) {
                $this->checkAndCloseTrade($trade, $trade->sl, true);
                return;
            }
            
            // Check TP progression based on High (for BUY) or Low (for SELL)
            $bestPrice = $trade->type === 'BUY' ? $high : $low;
            $this->checkAndCloseTrade($trade, $bestPrice, false);
            
            // If trade is closed, stop evaluating
            if ($trade->status !== 'open') return;
        }
        
        // Touch to update timestamp for next run
        $trade->touch();
    }

    private function checkAndCloseTrade(PaperTrade $trade, float $price, bool $forceSl = false): void {
        $session = $trade->session;
        $isBuy = $trade->type === 'BUY';
        
        $hitTp1 = $isBuy ? ($trade->tp1 && $price >= $trade->tp1) : ($trade->tp1 && $price <= $trade->tp1);
        $hitTp2 = $isBuy ? ($trade->tp2 && $price >= $trade->tp2) : ($trade->tp2 && $price <= $trade->tp2);
        $hitTp3 = $isBuy ? ($trade->tp3 && $price >= $trade->tp3) : ($trade->tp3 && $price <= $trade->tp3);
        $hitSl  = $forceSl || ($isBuy ? ($price <= $trade->sl) : ($price >= $trade->sl));

        $history = is_array($trade->history) ? $trade->history : [];
        $highestHit = $trade->highest_target_hit;
        $shouldClose = null;

        // Check timeline events incrementally
        if ($hitTp1 && !in_array($highestHit, ['tp1', 'tp2', 'tp3'])) {
            $history[] = ['event' => 'tp1', 'price' => $trade->tp1, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp1';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp1', $this->calcPnlPct($trade, $trade->tp1), $trade->recommendation?->strategy));
        }
        if ($hitTp2 && !in_array($highestHit, ['tp2', 'tp3'])) {
            $history[] = ['event' => 'tp2', 'price' => $trade->tp2, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp2';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp2', $this->calcPnlPct($trade, $trade->tp2), $trade->recommendation?->strategy));
        }
        if ($hitTp3 && $highestHit !== 'tp3') {
            $history[] = ['event' => 'tp3', 'price' => $trade->tp3, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp3';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp3', $this->calcPnlPct($trade, $trade->tp3), $trade->recommendation?->strategy));
        }

        if ($hitSl) {
            $history[] = ['event' => 'sl', 'price' => $trade->sl, 'timestamp' => now()->toIso8601String()];
            $shouldClose = 'closed_sl';
            $closePrice = $trade->sl;
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'sl', $this->calcPnlPct($trade, $trade->sl), $trade->recommendation?->strategy));
        } elseif (($trade->close_target === 'tp1' && $hitTp1) || 
                  ($trade->close_target === 'tp2' && $hitTp2) || 
                  ($trade->close_target === 'tp3' && $hitTp3)) {
            $shouldClose = 'closed_' . $trade->close_target;
            $closePrice = $trade->{$trade->close_target};
        }

        if ($shouldClose) {
            $pnlPercent = $this->calcPnlPct($trade, $closePrice);
            $pnl = ($closePrice - $trade->entry_price) * $trade->quantity * ($isBuy ? 1 : -1);
            $trade->update([
                'exit_price' => $closePrice, 
                'pnl' => $pnl, 
                'pnl_percent' => $pnlPercent, 
                'status' => $shouldClose, 
                'closed_at' => now(),
                'history' => $history,
                'highest_target_hit' => $highestHit
            ]);
            $returnAmount = $closePrice * $trade->quantity;
            $session->increment('current_balance', $returnAmount);
            $this->updateSessionStats($session);
            Log::info("Paper trade {$trade->id} closed: {$shouldClose} at {$closePrice}");
        } elseif (count($history) > count(is_array($trade->history) ? $trade->history : [])) {
            $trade->update(['history' => $history, 'highest_target_hit' => $highestHit]);
        }
    }

    private function calcPnlPct(PaperTrade $trade, float $price): float {
        return (($price - $trade->entry_price) / $trade->entry_price) * 100 * ($trade->type === 'BUY' ? 1 : -1);
    }
    
    private function updateSessionStats($session) {
        $total = $session->trades()->whereNotIn('status', ['open'])->count();
        $winning = $session->trades()->whereNotIn('status', ['open'])->where('pnl', '>', 0)->count();
        $session->update(['total_trades' => $total, 'winning_trades' => $winning, 'win_rate' => $total > 0 ? round(($winning/$total)*100, 2) : 0]);
    }
}
