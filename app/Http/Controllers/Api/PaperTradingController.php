<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Coin;
use App\Models\PaperTrade;
use App\Models\PaperTradingSession;
use App\Services\PythonTAService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PaperTradingController extends Controller {
    public function __construct(private PythonTAService $taService) {}

    public function getTradedCoins(Request $request): JsonResponse {
        $coins = PaperTrade::query()
            ->join('coins', 'paper_trades.coin_id', '=', 'coins.id')
            ->select('coins.symbol')
            ->distinct()
            ->pluck('symbol');
            
        // Make sure we just get base symbols (e.g. BTCUSDT -> BTC) or handle properly based on how DB stores it
        // Coins table usually stores 'symbol' as BTCUSDT or BTC
        
        return response()->json([
            'success' => true,
            'coins' => $coins
        ]);
    }

    public function sessions(Request $request): JsonResponse {
        $sessions = $request->user()->paperSessions()->latest()->get();
        $sessions->each(function($session) {
            $session->total_profit = $session->totalProfit();
            $session->total_loss = $session->totalLoss();
        });
        return response()->json(['sessions' => $sessions]);
    }

    public function createSession(Request $request): JsonResponse {
        $validator = Validator::make($request->all(), [
            'initial_balance' => 'sometimes|numeric|min:100|max:1000000',
            'target_balance'  => 'sometimes|numeric|min:0'
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $user = $request->user();
        $activeSession = $user->activePaperSession;
        if ($activeSession) return response()->json(['message' => 'You already have an active session', 'session' => $activeSession], 409);
        $balance = $request->get('initial_balance', 1000.00);
        $session = PaperTradingSession::create([
            'user_id' => $user->id,
            'initial_balance' => $balance,
            'current_balance' => $balance,
            'target_balance'  => $request->get('target_balance'),
            'status' => 'active',
            'started_at' => now()
        ]);
        return response()->json(['session' => $session], 201);
    }

    public function session(Request $request, int $id): JsonResponse {
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($id);
        $session->load([
            'trades.coin',
            'trades.recommendation' => function ($q) {
                $q->select('id', 'coin_id', 'timeframe', 'action', 'strategy', 'confidence', 'risk_reward', 'entry_price', 'tp1', 'tp2', 'tp3', 'sl', 'confluences', 'analysis_id', 'created_at');
            },
            // Exclude raw_data entirely — it can be 2-5MB per row and is not needed here
            'trades.recommendation.analysis' => function ($q) {
                $q->select('id', 'coin_id', 'timeframe', 'overall_bias', 'overall_confidence', 'confluences', 'analyzed_at');
            },
        ]);
        $session->pnl_percent = $session->pnlPercent();
        $session->total_profit = $session->totalProfit();
        $session->total_loss = $session->totalLoss();
        return response()->json(['session' => $session]);
    }

    public function openTrade(Request $request): JsonResponse {
        $validator = Validator::make($request->all(), [
            'session_id'         => 'required|exists:paper_trading_sessions,id',
            'coin_id'            => 'required|exists:coins,id',
            'recommendation_id'  => 'sometimes|exists:recommendations,id',
            'type'               => 'required|in:BUY,SELL',
            'quantity'           => 'required|numeric|min:0.00001',
            'entry_price'        => 'sometimes|numeric',
            'tp1'                => 'sometimes|numeric',
            'tp2'                => 'sometimes|numeric',
            'tp3'                => 'sometimes|numeric',
            'sl'                 => 'required|numeric',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->where('status', 'active')->findOrFail($request->session_id);
        $coin = Coin::findOrFail($request->coin_id);
        $entryPrice = $request->entry_price ?? ($this->taService->getPrice($coin->symbol) ?? $coin->current_price);

        // Guard: the SL/TP levels attached to this request were computed against the price
        // at recommendation time, but entryPrice above is a fresh live quote. If price moved
        // between the two, the SL can end up on the wrong side of (or already past) the new
        // entry — opening a trade that is already invalid. Reject rather than open it dead.
        $isBuy = $request->type === 'BUY';
        $sl    = (float) $request->sl;
        $slInvalid = $isBuy ? ($sl >= $entryPrice) : ($sl <= $entryPrice);
        if ($slInvalid) {
            return response()->json([
                'message' => 'Price moved since this recommendation was generated — the stop-loss is no longer valid at the current price. Please re-run the analysis before opening this trade.',
                'entry_price' => $entryPrice,
                'sl' => $sl,
            ], 409);
        }

        $cost = $entryPrice * $request->quantity;
        if ($cost > $session->current_balance) {
            // Auto-top-up balance to allow unlimited trade tracking
            $session->increment('current_balance', 10000);
            $session->increment('initial_balance', 10000);
        }
        $trade = PaperTrade::create([
            'session_id'        => $session->id,
            'coin_id'           => $request->coin_id,
            'recommendation_id' => $request->recommendation_id,
            'type'              => $request->type,
            'entry_price'       => $entryPrice,
            'quantity'          => $request->quantity,
            'tp1'               => $request->tp1,
            'tp2'               => $request->tp2,
            'tp3'               => $request->tp3,
            'sl'                => $request->sl,
            'close_target'      => $request->close_target ?? 'tp3',
            'history'           => [],
            'status'            => 'open',
            'opened_at'         => now()
        ]);
        $session->decrement('current_balance', $cost);
        return response()->json(['trade' => $trade->load('coin')], 201);
    }

    public function closeTrade(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::where('status', 'open')->findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        $exitPrice = $this->taService->getPrice($trade->coin->symbol) ?? $trade->entry_price;
        $pnl = ($exitPrice - $trade->entry_price) * $trade->quantity * ($trade->type === 'BUY' ? 1 : -1);
        $pnlPercent = (($exitPrice - $trade->entry_price) / $trade->entry_price) * 100 * ($trade->type === 'BUY' ? 1 : -1);
        $trade->update(['exit_price' => $exitPrice, 'pnl' => $pnl, 'pnl_percent' => $pnlPercent, 'status' => 'closed_manual', 'closed_at' => now()]);
        $cost = $trade->entry_price * $trade->quantity;
        $returnAmount = $cost + $pnl;
        $session->increment('current_balance', $returnAmount);
        $this->updateSessionStats($session);
        return response()->json(['trade' => $trade, 'pnl' => $pnl, 'new_balance' => $session->fresh()->current_balance]);
    }

    public function updateTarget(Request $request, int $id): JsonResponse {
        $request->validate(['close_target' => 'required|in:tp1,tp2,tp3']);
        $trade = PaperTrade::where('status', 'open')->findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        $trade->update(['close_target' => $request->close_target]);
        return response()->json(['message' => 'Target updated successfully', 'trade' => $trade]);
    }

    public function updateTradeSettings(Request $request, int $id): JsonResponse {
        $request->validate([
            'sl' => 'required|numeric',
            'custom_tp_percent' => 'nullable|numeric',
            'entry_price' => 'sometimes|numeric'
        ]);
        $trade = PaperTrade::where('status', 'open')->findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        
        $updateData = [
            'sl' => $request->sl,
            'custom_tp_percent' => $request->custom_tp_percent
        ];
        
        if ($request->has('entry_price')) {
            $newEntry = (float) $request->entry_price;
            $oldEntry = (float) $trade->entry_price;
            
            // Adjust the user's active session balance to match the corrected entry cost
            $diff = ($newEntry - $oldEntry) * $trade->quantity;
            $session->decrement('current_balance', $diff);
            
            $updateData['entry_price'] = $newEntry;
        }
        
        $trade->update($updateData);
        return response()->json(['message' => 'Trade settings updated successfully', 'trade' => $trade]);
    }

    public function trades(Request $request): JsonResponse {
        $user = $request->user();
        $sessionId = $request->get('session_id');
        $query = PaperTrade::whereHas('session', fn($q) => $q->where('user_id', $user->id));
        if ($sessionId) $query->where('session_id', $sessionId);
        
        if ($status = $request->get('status')) {
            if ($status === 'closed') {
                $query->where('status', '!=', 'open');
            } else {
                $query->where('status', $status);
            }
        }
        
        $trades = $query->with([
            'coin',
            'recommendation.analysis' => function ($q) {
                // Exclude massive 'raw_data' column to prevent PHP memory exhaustion
                $q->select('id', 'coin_id', 'timeframe', 'overall_bias', 'overall_confidence', 'confluences', 'analyzed_at', 'classical_data', 'smc_data');
            }
        ])->latest('opened_at')->paginate(20);
        return response()->json($trades);
    }

    private function updateSessionStats(PaperTradingSession $session): void {
        // Use aggregate queries instead of loading all trades into memory
        $total   = $session->trades()->whereNotIn('status', ['open'])->count();
        $winning = $session->trades()->whereNotIn('status', ['open'])->where('pnl', '>', 0)->count();
        $session->update([
            'total_trades'   => $total,
            'winning_trades' => $winning,
            'win_rate'       => $total > 0 ? round(($winning / $total) * 100, 2) : 0
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        
        $cost = $trade->entry_price * $trade->quantity;
        if ($trade->status === 'open') {
            $session->increment('current_balance', $cost);
        } else {
            $session->decrement('current_balance', $trade->pnl ?? 0);
        }
        
        $trade->delete();
        $this->updateSessionStats($session);
        return response()->json(['message' => 'Trade deleted successfully']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RE-ANALYZE: Fetch fresh TA data and give a deterministic HOLD/WATCH/EXIT
    // verdict for an open trade — no AI LLM, pure rule-based logic.
    // ─────────────────────────────────────────────────────────────────────────
    public function reanalyze(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::where('status', 'open')
            ->with(['coin', 'recommendation.analysis'])
            ->findOrFail($id);

        // Authorization: trade must belong to the requesting user
        PaperTradingSession::where('user_id', $request->user()->id)
            ->findOrFail($trade->session_id);

        $symbol    = $trade->coin->symbol;
        $isBuy     = $trade->type === 'BUY';
        $entry     = (float) $trade->entry_price;
        $sl        = (float) $trade->sl;
        $tp1       = (float) ($trade->tp1 ?? 0);
        $tp2       = (float) ($trade->tp2 ?? 0);
        $tp3       = (float) ($trade->tp3 ?? 0);
        $timeframe = $trade->recommendation?->timeframe ?? '15m';

        // ── 1. Fetch live TA data ─────────────────────────────────────────
        try {
            $ta = $this->taService->analyze($symbol, 'binance', $timeframe, 300, false, true, false);
        } catch (\Exception $e) {
            return response()->json(['error' => 'TA service unavailable: ' . $e->getMessage()], 503);
        }

        // ── 2. Extract key signals ────────────────────────────────────────
        $currentPrice = $this->taService->getPrice($symbol) ?? ($ta['classical']['current_price'] ?? $entry);
        $currentPrice = (float) $currentPrice;

        $classical   = $ta['classical']      ?? [];
        $smc         = $ta['smc']            ?? [];
        $structure   = $smc['market_structure'] ?? [];

        // Bias
        $classicalBias = strtoupper($classical['overall_bias'] ?? 'NEUTRAL');
        $smcBias       = strtoupper($smc['bias']               ?? 'NEUTRAL');
        // Combine: if both agree → strong; if one is neutral → moderate; if opposite → conflicting
        $biasAligned  = $isBuy
            ? ($classicalBias === 'BULLISH' || $smcBias === 'BULLISH')
            : ($classicalBias === 'BEARISH' || $smcBias === 'BEARISH');
        $biasConflict = $isBuy
            ? ($classicalBias === 'BEARISH' && $smcBias === 'BEARISH')
            : ($classicalBias === 'BULLISH' && $smcBias === 'BULLISH');
        $bothAligned  = $isBuy
            ? ($classicalBias === 'BULLISH' && $smcBias === 'BULLISH')
            : ($classicalBias === 'BEARISH' && $smcBias === 'BEARISH');

        // Market structure
        $structureTrend = strtolower($structure['trend'] ?? 'ranging');
        $structureBreak = $structure['recent_bos'] ?? false;   // BOS detected
        $structureDir   = strtoupper($structure['direction'] ?? 'NEUTRAL');
        $structureAgainstTrade = $isBuy
            ? ($structureDir === 'BEARISH' && $structureBreak)
            : ($structureDir === 'BULLISH' && $structureBreak);

        // RSI
        $rsi = (float) ($classical['rsi'] ?? 50);
        $rsiOverbought = $rsi >= 72;
        $rsiOversold   = $rsi <= 28;
        $rsiWeakForTrade = $isBuy ? $rsiOverbought : $rsiOversold;

        // Key Supports / Resistances
        $supports    = array_map('floatval', $classical['key_supports']    ?? []);
        $resistances = array_map('floatval', $classical['key_resistances'] ?? []);
        rsort($supports);    // highest support first
        sort($resistances);  // lowest resistance first

        // Nearest support below current price (for BUY trades)
        $nearestSupportBelow = null;
        foreach ($supports as $s) {
            if ($s < $currentPrice) { $nearestSupportBelow = $s; break; }
        }
        // Nearest resistance above current price (for SELL trades)
        $nearestResistanceAbove = null;
        foreach ($resistances as $r) {
            if ($r > $currentPrice) { $nearestResistanceAbove = $r; break; }
        }

        // Distance metrics (% of entry price)
        $totalRiskPct   = abs($entry - $sl)  / $entry * 100;
        $distToSlPct    = abs($currentPrice - $sl)  / $entry * 100;
        $distToTp1Pct   = $tp1 > 0 ? abs($tp1 - $currentPrice) / $entry * 100 : 999;

        // How far have we travelled toward SL? (0 = at entry, 100 = at SL)
        $slHit = $isBuy ? ($currentPrice <= $sl) : ($currentPrice >= $sl);
        if ($slHit) {
            $progressToSl = 100;
            $movingTowardSl = true;
        } else {
            $progressToSl = $totalRiskPct > 0
                ? (abs($currentPrice - $entry) / abs($sl - $entry)) * 100
                : 0;
            $movingTowardSl = $isBuy ? $currentPrice < $entry : $currentPrice > $entry;
            if (!$movingTowardSl) $progressToSl = 0; // price moved toward TP, not SL
        }

        // FVG blocking analysis (bearish FVG above for BUY, bullish FVG below for SELL)
        $fvgList = $smc['fvg'] ?? [];
        $blockingFvg = null;
        foreach ($fvgList as $fvg) {
            if (!isset($fvg['type'], $fvg['top'], $fvg['bottom'])) continue;
            $fvgTop    = (float) $fvg['top'];
            $fvgBottom = (float) $fvg['bottom'];
            $fvgFilled = (bool) ($fvg['is_filled'] ?? false);
            if ($fvgFilled) continue;

            if ($isBuy && strtolower($fvg['type']) === 'bearish') {
                // Bearish FVG sitting above current price, within 1% — blocking upward move
                if ($fvgBottom > $currentPrice && ($fvgBottom - $currentPrice) / $currentPrice <= 0.01) {
                    $blockingFvg = $fvg;
                    break;
                }
            } elseif (!$isBuy && strtolower($fvg['type']) === 'bullish') {
                // Bullish FVG below current price, within 1% — blocking downward move
                if ($fvgTop < $currentPrice && ($currentPrice - $fvgTop) / $currentPrice <= 0.01) {
                    $blockingFvg = $fvg;
                    break;
                }
            }
        }

        // Key level broken check: did price breach the nearest support (BUY) or resistance (SELL)?
        $keyLevelBroken = false;
        if ($isBuy && $nearestSupportBelow !== null) {
            // Support very close to SL already? If price is below strongest support we had
            $strongestSupport = $supports[0] ?? null;
            if ($strongestSupport && $currentPrice < $strongestSupport && $currentPrice > $sl) {
                $keyLevelBroken = true;
            }
        } elseif (!$isBuy && $nearestResistanceAbove !== null) {
            $strongestResistance = $resistances[0] ?? null;
            if ($strongestResistance && $currentPrice > $strongestResistance && $currentPrice < $sl) {
                $keyLevelBroken = true;
            }
        }

        // MA Trend
        $maTrend = strtolower($classical['ma_trend'] ?? 'ranging');
        $maSupportsTrade = $isBuy
            ? str_contains($maTrend, 'uptrend')
            : str_contains($maTrend, 'downtrend');

        // ── 3. Verdict logic ─────────────────────────────────────────────
        $reasons  = [];
        $exitScore = 0;
        $watchScore = 0;
        $holdScore  = 0;

        // --- HARD EXIT signals (critical severity) ---
        if ($slHit) {
            $exitScore += 100;
            $reasons['exit'][] = "CRITICAL: Price has hit or crossed the Stop Loss level. Immediate exit required to prevent further loss.";
        }

        // --- EXIT signals (high severity) ---
        if ($biasConflict && $structureAgainstTrade) {
            $exitScore += 40;
            $reasons['exit'][] = "Bias fully reversed ({$classicalBias} Classical, {$smcBias} SMC) AND market structure broke against the trade — strong reversal signal";
        }
        if ($keyLevelBroken) {
            $exitScore += 30;
            $reasons['exit'][] = "Key price level breached — the structural support/resistance that was protecting this trade has failed";
        }
        if ($biasConflict && $progressToSl > 60) {
            $exitScore += 25;
            $reasons['exit'][] = "Price has moved " . round($progressToSl) . "% of the way toward SL with fully bearish/bullish bias against trade";
        }
        if ($structureAgainstTrade && $progressToSl > 70) {
            $exitScore += 20;
            $reasons['exit'][] = "Market structure broke against trade AND price is " . round($progressToSl) . "% toward SL with no confirmed reversal";
        }

        // --- WATCH signals (medium severity) ---
        if ($biasConflict && !$structureAgainstTrade) {
            $watchScore += 20;
            $reasons['watch'][] = "Both Classical and SMC bias turned against the trade direction, though structure hasn't confirmed a break yet";
        }
        if (!$biasAligned && $progressToSl > 40) {
            $watchScore += 15;
            $reasons['watch'][] = "Bias is no longer supporting the trade AND price has drifted " . round($progressToSl) . "% toward the stop-loss";
        }
        if ($rsiWeakForTrade) {
            $watchScore += 12;
            $reasons['watch'][] = "RSI at " . round($rsi, 1) . " — " . ($isBuy ? "overbought territory signals exhaustion of the bullish move" : "oversold territory signals exhaustion of the bearish move");
        }
        if ($blockingFvg) {
            $fvgPct = round(abs($blockingFvg['bottom'] - $currentPrice) / $currentPrice * 100, 2);
            $watchScore += 15;
            $reasons['watch'][] = "Unfilled " . ($isBuy ? "BEARISH" : "BULLISH") . " Fair Value Gap blocking further progress, just {$fvgPct}% away at " . number_format((float)($isBuy ? $blockingFvg['bottom'] : $blockingFvg['top']), 4);
        }
        if ($movingTowardSl && $progressToSl > 50 && !$keyLevelBroken && !$biasConflict) {
            $watchScore += 10;
            $reasons['watch'][] = "Price has retraced " . round($progressToSl) . "% of the risk distance toward SL — consider tightening stop or partial exit";
        }
        if ($structureAgainstTrade && !$biasConflict) {
            $watchScore += 15;
            $reasons['watch'][] = "Market structure shows a Break of Structure against the trade, though overall bias hasn't fully flipped yet";
        }

        // --- HOLD signals ---
        if ($bothAligned) {
            $holdScore += 30;
            $reasons['hold'][] = "Both Classical ({$classicalBias}) and SMC ({$smcBias}) bias fully aligned with the " . ($isBuy ? "BUY" : "SELL") . " trade direction";
        } elseif ($biasAligned) {
            $holdScore += 15;
            $reasons['hold'][] = "At least one analysis method (" . ($classicalBias === ($isBuy ? 'BULLISH' : 'BEARISH') ? 'Classical' : 'SMC') . ") supports the trade direction";
        }
        if (!$structureAgainstTrade) {
            $holdScore += 15;
            $reasons['hold'][] = "Market structure remains intact — no confirmed Break of Structure against the trade";
        }
        if ($maSupportsTrade) {
            $holdScore += 10;
            $reasons['hold'][] = "Moving Average trend (" . $maTrend . ") still in favour of the trade";
        }
        if ($progressToSl < 30 && $biasAligned && !$slHit) {
            $holdScore += 10;
            $reasons['hold'][] = "Price is only " . round($progressToSl) . "% toward SL and bias is still supportive — setup remains valid";
        }
        if (!$movingTowardSl && $tp1 > 0) {
            $holdScore += 10;
            $reasons['hold'][] = "Price is currently moving toward the take-profit targets, not the stop-loss";
        }

        // ── 4. Final verdict ─────────────────────────────────────────────
        if ($slHit) {
            $verdict    = 'EXIT';
            $confidence = 100;
        } elseif ($exitScore >= 40) {
            $verdict    = 'EXIT';
            $confidence = min(95, 50 + $exitScore);
        } elseif ($exitScore >= 25 || $watchScore >= 35) {
            $verdict    = 'WATCH';
            $confidence = min(85, 50 + max($exitScore, $watchScore));
        } elseif ($holdScore >= 30) {
            $verdict    = 'HOLD';
            $confidence = min(90, 40 + $holdScore);
        } elseif ($watchScore >= 15) {
            $verdict    = 'WATCH';
            $confidence = min(75, 50 + $watchScore);
        } else {
            $verdict    = 'HOLD';
            $confidence = 55;
        }

        // ── 5. Position context ──────────────────────────────────────────
        $pnlNow      = ($currentPrice - $entry) * $trade->quantity * ($isBuy ? 1 : -1);
        $pnlPctNow   = (($currentPrice - $entry) / $entry) * 100 * ($isBuy ? 1 : -1);
        $distSlPct   = round($distToSlPct, 3);
        $distTp1Pct  = round($distToTp1Pct, 3);

        // Collect the most relevant reasons for the chosen verdict
        $primaryReasons = [];
        if ($verdict === 'EXIT') {
            $primaryReasons = array_merge($reasons['exit'] ?? [], array_slice($reasons['watch'] ?? [], 0, 1));
        } elseif ($verdict === 'WATCH') {
            $primaryReasons = array_merge($reasons['watch'] ?? [], array_slice($reasons['exit'] ?? [], 0, 1));
        } else {
            $primaryReasons = array_merge($reasons['hold'] ?? [], array_slice($reasons['watch'] ?? [], 0, 1));
        }

        return response()->json([
            'verdict'       => $verdict,
            'confidence'    => $confidence,
            'reasons'       => $primaryReasons,
            'all_reasons'   => $reasons,
            'timeframe'     => $timeframe,
            'context' => [
                'current_price'   => $currentPrice,
                'entry_price'     => $entry,
                'sl'              => $sl,
                'tp1'             => $tp1,
                'pnl_now'         => round($pnlNow, 4),
                'pnl_pct_now'     => round($pnlPctNow, 3),
                'progress_to_sl'  => round($progressToSl, 1),
                'dist_to_sl_pct'  => $distSlPct,
                'dist_to_tp1_pct' => $distTp1Pct,
                'moving_to_sl'    => $movingTowardSl,
            ],
            'signals' => [
                'classical_bias'       => $classicalBias,
                'smc_bias'             => $smcBias,
                'bias_aligned'         => $biasAligned,
                'bias_conflict'        => $biasConflict,
                'structure_trend'      => $structureTrend,
                'structure_bos'        => $structureBreak,
                'structure_against'    => $structureAgainstTrade,
                'rsi'                  => round($rsi, 1),
                'rsi_weak_for_trade'   => $rsiWeakForTrade,
                'ma_trend'             => $maTrend,
                'ma_supports_trade'    => $maSupportsTrade,
                'blocking_fvg'         => !is_null($blockingFvg),
                'key_level_broken'     => $keyLevelBroken,
            ],
            'scores' => [
                'exit'  => $exitScore,
                'watch' => $watchScore,
                'hold'  => $holdScore,
            ],
            'analyzed_at' => now()->toIso8601String(),
        ]);
    }

    public function postCloseAnalysis(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::with('coin')->findOrFail($id);

        if (!$trade->closed_at) {
            return response()->json(['error' => 'Trade does not have a closed_at timestamp.'], 400);
        }

        if ($trade->hindsight_result && isset($trade->hindsight_result['closed_by'])) {
            return response()->json([
                'trade_id' => $trade->id,
                'simulation' => $trade->hindsight_result,
                'analyzed_candles_count' => 0,
                'timestamp' => now()->toIso8601String(),
                'cached' => true
            ]);
        }

        $startTime = strtotime($trade->closed_at) * 1000;
        $symbol = strtoupper($trade->coin->symbol);

        $response = Http::withoutVerifying()->get("https://api.binance.com/api/v3/klines", [
            'symbol' => $symbol,
            'interval' => '1m',
            'startTime' => $startTime,
            'limit' => 1000
        ]);

        if (!$response->successful()) {
            return response()->json(['error' => 'Failed to fetch historical data from Binance'], 500);
        }

        // Process candles one-by-one to avoid loading 1000 elements into memory at once
        $klines = $response->json();
        unset($response); // free HTTP response memory immediately
        
        $isBuy = $trade->type === 'BUY';
        $sl = (float) $trade->sl;
        $tp1 = (float) $trade->tp1;
        $tp2 = (float) $trade->tp2;
        $tp3 = (float) $trade->tp3;
        
        $state = [
            'sl_hit' => false,
            'tp1_hit' => false,
            'tp2_hit' => false,
            'tp3_hit' => false,
            'sl_hit_time' => null,
            'tp1_hit_time' => null,
            'tp2_hit_time' => null,
            'tp3_hit_time' => null,
            'closed_by' => null,
        ];

        foreach ($klines as $candle) {
            $timestamp = $candle[0]; // Open time
            $high = (float) $candle[2];
            $low = (float) $candle[3];

            if ($isBuy) {
                if (!$state['sl_hit'] && $sl > 0 && $low <= $sl) {
                    $state['sl_hit'] = true;
                    $state['sl_hit_time'] = $timestamp;
                    if (!$state['closed_by']) $state['closed_by'] = 'SL';
                    break;
                }
                if ($tp1 > 0 && !$state['tp1_hit'] && $high >= $tp1) {
                    $state['tp1_hit'] = true;
                    $state['tp1_hit_time'] = $timestamp;
                }
                if ($tp2 > 0 && !$state['tp2_hit'] && $high >= $tp2) {
                    $state['tp2_hit'] = true;
                    $state['tp2_hit_time'] = $timestamp;
                }
                if ($tp3 > 0 && !$state['tp3_hit'] && $high >= $tp3) {
                    $state['tp3_hit'] = true;
                    $state['tp3_hit_time'] = $timestamp;
                    if (!$state['closed_by']) $state['closed_by'] = 'TP3';
                    break;
                }
            } else { // SELL trade
                if (!$state['sl_hit'] && $sl > 0 && $high >= $sl) {
                    $state['sl_hit'] = true;
                    $state['sl_hit_time'] = $timestamp;
                    if (!$state['closed_by']) $state['closed_by'] = 'SL';
                    break;
                }
                if ($tp1 > 0 && !$state['tp1_hit'] && $low <= $tp1) {
                    $state['tp1_hit'] = true;
                    $state['tp1_hit_time'] = $timestamp;
                }
                if ($tp2 > 0 && !$state['tp2_hit'] && $low <= $tp2) {
                    $state['tp2_hit'] = true;
                    $state['tp2_hit_time'] = $timestamp;
                }
                if ($tp3 > 0 && !$state['tp3_hit'] && $low <= $tp3) {
                    $state['tp3_hit'] = true;
                    $state['tp3_hit_time'] = $timestamp;
                    if (!$state['closed_by']) $state['closed_by'] = 'TP3';
                    break;
                }
            }
        }

        $trade->update(['hindsight_result' => $state]);

        return response()->json([
            'trade_id' => $trade->id,
            'simulation' => $state,
            'analyzed_candles_count' => count($klines),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    public function checkAfterSlTargets(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::with('coin')->findOrFail($id);

        if ($trade->status === 'open') {
            return response()->json(['error' => 'Trade is still open.'], 400);
        }

        $startTime = strtotime($trade->closed_at ?? $trade->updated_at) * 1000;
        $symbol = strtoupper($trade->coin->symbol);

        $response = Http::withoutVerifying()->get("https://api.binance.com/api/v3/klines", [
            'symbol' => $symbol,
            'interval' => '1m',
            'startTime' => $startTime,
            'limit' => 1000
        ]);

        if (!$response->successful()) {
            return response()->json(['error' => 'Failed to fetch historical data from Binance'], 500);
        }

        $klines = $response->json();
        unset($response);
        
        $isBuy = $trade->type === 'BUY';
        $tp1 = (float) $trade->tp1;
        $tp2 = (float) $trade->tp2;
        $tp3 = (float) $trade->tp3;
        
        $state = [
            'tp1_hit' => false,
            'tp2_hit' => false,
            'tp3_hit' => false,
            'highest_hit' => null
        ];

        foreach ($klines as $candle) {
            $high = (float) $candle[2];
            $low = (float) $candle[3];

            if ($isBuy) {
                if ($tp1 > 0 && !$state['tp1_hit'] && $high >= $tp1) {
                    $state['tp1_hit'] = true;
                    if (!$state['highest_hit']) $state['highest_hit'] = 'tp1';
                }
                if ($tp2 > 0 && !$state['tp2_hit'] && $high >= $tp2) {
                    $state['tp2_hit'] = true;
                    if ($state['highest_hit'] === 'tp1' || !$state['highest_hit']) $state['highest_hit'] = 'tp2';
                }
                if ($tp3 > 0 && !$state['tp3_hit'] && $high >= $tp3) {
                    $state['tp3_hit'] = true;
                    $state['highest_hit'] = 'tp3';
                    break;
                }
            } else {
                if ($tp1 > 0 && !$state['tp1_hit'] && $low <= $tp1) {
                    $state['tp1_hit'] = true;
                    if (!$state['highest_hit']) $state['highest_hit'] = 'tp1';
                }
                if ($tp2 > 0 && !$state['tp2_hit'] && $low <= $tp2) {
                    $state['tp2_hit'] = true;
                    if ($state['highest_hit'] === 'tp1' || !$state['highest_hit']) $state['highest_hit'] = 'tp2';
                }
                if ($tp3 > 0 && !$state['tp3_hit'] && $low <= $tp3) {
                    $state['tp3_hit'] = true;
                    $state['highest_hit'] = 'tp3';
                    break;
                }
            }
        }

        return response()->json([
            'trade_id' => $trade->id,
            'result' => $state
        ]);
    }
}
