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
        $openTrades = PaperTrade::where('status', 'open')->with(['coin', 'session'])->get();
        foreach ($openTrades as $trade) {
            try {
                $currentPrice = $taService->getPrice($trade->coin->symbol);
                if (!$currentPrice) continue;
                $this->checkAndCloseTrade($trade, $currentPrice);
            } catch (\Exception $e) {
                Log::error('UpdatePaperTradesJob error', ['trade' => $trade->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function checkAndCloseTrade(PaperTrade $trade, float $price): void {
        $session = $trade->session;
        $isBuy = $trade->type === 'BUY';
        
        $hitTp1 = $isBuy ? ($trade->tp1 && $price >= $trade->tp1) : ($trade->tp1 && $price <= $trade->tp1);
        $hitTp2 = $isBuy ? ($trade->tp2 && $price >= $trade->tp2) : ($trade->tp2 && $price <= $trade->tp2);
        $hitTp3 = $isBuy ? ($trade->tp3 && $price >= $trade->tp3) : ($trade->tp3 && $price <= $trade->tp3);
        $hitSl  = $isBuy ? ($price <= $trade->sl) : ($price >= $trade->sl);

        $history = is_array($trade->history) ? $trade->history : [];
        $highestHit = $trade->highest_target_hit;
        $shouldClose = null;

        // Check timeline events incrementally
        if ($hitTp1 && !in_array($highestHit, ['tp1', 'tp2', 'tp3'])) {
            $history[] = ['event' => 'tp1', 'price' => $price, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp1';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp1', $this->calcPnlPct($trade, $price)));
        }
        if ($hitTp2 && !in_array($highestHit, ['tp2', 'tp3'])) {
            $history[] = ['event' => 'tp2', 'price' => $price, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp2';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp2', $this->calcPnlPct($trade, $price)));
        }
        if ($hitTp3 && $highestHit !== 'tp3') {
            $history[] = ['event' => 'tp3', 'price' => $price, 'timestamp' => now()->toIso8601String()];
            $highestHit = 'tp3';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'tp3', $this->calcPnlPct($trade, $price)));
        }

        if ($hitSl) {
            $history[] = ['event' => 'sl', 'price' => $price, 'timestamp' => now()->toIso8601String()];
            $shouldClose = 'closed_sl';
            event(new TradeTargetHit($trade->coin->symbol, $trade->type, 'sl', $this->calcPnlPct($trade, $price)));
        } elseif (($trade->close_target === 'tp1' && $hitTp1) || 
                  ($trade->close_target === 'tp2' && $hitTp2) || 
                  ($trade->close_target === 'tp3' && $hitTp3)) {
            $shouldClose = 'closed_' . $trade->close_target;
        }

        if ($shouldClose) {
            $pnlPercent = $this->calcPnlPct($trade, $price);
            $pnl = ($price - $trade->entry_price) * $trade->quantity * ($isBuy ? 1 : -1);
            $trade->update([
                'exit_price' => $price, 
                'pnl' => $pnl, 
                'pnl_percent' => $pnlPercent, 
                'status' => $shouldClose, 
                'closed_at' => now(),
                'history' => $history,
                'highest_target_hit' => $highestHit
            ]);
            $returnAmount = $price * $trade->quantity;
            $session->increment('current_balance', $returnAmount);
            $this->updateSessionStats($session);
            Log::info("Paper trade {$trade->id} closed: {$shouldClose} at {$price}");
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
