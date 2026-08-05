<?php

namespace App\Services;

use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TradeTrackerService
{
    /**
     * Evaluates an active trade by fetching 1m klines from Binance since it was created.
     */
    public function evaluateChronologically(Recommendation $rec): void
    {
        if (!in_array($rec->status, ['active', 'pending'])) {
            return;
        }

        // We need the symbol string, e.g., 'BTCUSDT'
        $symbol = $rec->coin->symbol;
        $startTime = $rec->created_at->timestamp * 1000;
        
        // Binance API for 1m klines
        // Limit 1000 candles is max for Binance. 1000 minutes = ~16.6 hours.
        $url = "https://api.binance.com/api/v3/klines";
        
        try {
            $response = Http::withoutVerifying()->get($url, [
                'symbol' => $symbol,
                'interval' => '1m',
                'startTime' => $startTime,
                'limit' => 1000
            ]);

            if (!$response->successful()) {
                Log::warning("TradeTrackerService: Failed to fetch klines for {$symbol}");
                return;
            }

            $klines = $response->json();
            
            $entry = (float) $rec->entry_price;
            $tp1 = $rec->tp1 ? (float) $rec->tp1 : null;
            $tp2 = $rec->tp2 ? (float) $rec->tp2 : null;
            $tp3 = $rec->tp3 ? (float) $rec->tp3 : null;
            $sl = $rec->sl ? (float) $rec->sl : null;

            // The recommendation was created at this timestamp (ms)
            // We must only evaluate candles that OPENED at or after this time
            // to avoid false hits from the candle that was already in-progress
            // when the recommendation was saved (Bug: TP hit 1 second before entry).
            $recCreatedMs = $rec->created_at->timestamp * 1000;

            $newStatus = null;

            foreach ($klines as $kline) {
                // kline format: [Open time, Open, High, Low, Close, Volume, Close time, ...]
                $candleOpenMs = (int) $kline[0];
                $high = (float) $kline[2];
                $low = (float) $kline[3];

                // Skip candles that opened before the recommendation was created.
                // A 1m candle opening at 10:58:00 covers 10:58:00-10:58:59;
                // if the recommendation was created at 10:58:02, the first 2 seconds
                // of price action are irrelevant (the trade didn't exist yet).
                if ($candleOpenMs < $recCreatedMs) {
                    continue;
                }

                if ($rec->action === 'BUY') {
                    // Check SL first within the same 1m candle (pessimistic)
                    if ($sl !== null && $low <= $sl) {
                        $newStatus = 'hit_sl';
                        break;
                    }
                    
                    if ($tp3 !== null && $high >= $tp3) {
                        $newStatus = 'hit_tp3';
                        break;
                    } elseif ($tp2 !== null && $high >= $tp2) {
                        $newStatus = 'hit_tp2';
                    } elseif ($tp1 !== null && $high >= $tp1 && !in_array($newStatus, ['hit_tp2', 'hit_tp3'])) {
                        $newStatus = 'hit_tp1';
                    }
                } elseif ($rec->action === 'SELL') {
                    // Check SL first within the same 1m candle
                    if ($sl !== null && $high >= $sl) {
                        $newStatus = 'hit_sl';
                        break;
                    }
                    
                    if ($tp3 !== null && $low <= $tp3) {
                        $newStatus = 'hit_tp3';
                        break;
                    } elseif ($tp2 !== null && $low <= $tp2) {
                        $newStatus = 'hit_tp2';
                    } elseif ($tp1 !== null && $low <= $tp1 && !in_array($newStatus, ['hit_tp2', 'hit_tp3'])) {
                        $newStatus = 'hit_tp1';
                    }
                }
            }

            // If no TP or SL was hit but time expired
            if (!$newStatus && $rec->expires_at && now()->greaterThanOrEqualTo($rec->expires_at)) {
                $newStatus = 'expired';
            }

            if ($newStatus) {
                $rec->update(['status' => $newStatus]);
                Log::info("TradeTrackerService: Updated recommendation {$rec->id} for {$symbol} to {$newStatus}");
            }

        } catch (\Exception $e) {
            Log::error("TradeTrackerService: Exception evaluating {$symbol} - " . $e->getMessage());
        }
    }
}
