<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Analysis;
use App\Models\Coin;
use App\Models\Recommendation;
use App\Services\PythonTAService;
use App\Services\OpenRouterService;
use App\Services\CryptoPanicService;
use App\Services\WhaleAlertService;
use App\Services\AgentBridgeService;
use App\Http\Controllers\Api\SettingsController;
use App\Events\MarketScanProgress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AnalysisController extends Controller
{
    public function __construct(
        private PythonTAService    $taService,
        private OpenRouterService  $aiService,
        private CryptoPanicService $newsService,
        private WhaleAlertService  $whaleService,
    ) {}

    public function analyze(Request $request, string $symbol, string $timeframe): JsonResponse
    {
        set_time_limit(0);
        $symbol = strtoupper($symbol);
        $coin   = Coin::where('symbol', $symbol)->firstOrFail();

        try {
            $isAutoScan = $request->boolean('is_trending', false);
            $settings   = $this->loadAiSettings();

            // ── Determine if MTF mode is active ──────────────────────────────
            $htfTf = $settings['mtf_htf'] ?? null;
            $mtfTf = $settings['mtf_mtf'] ?? null;
            $ltfTf = $settings['mtf_ltf'] ?? null;

            // MTF is active only when all 3 are set and all different from each other
            $mtfActive = $htfTf && $mtfTf && $ltfTf
                && $htfTf !== $mtfTf && $mtfTf !== $ltfTf && $htfTf !== $ltfTf;

            $exchange = $coin->exchange ?? 'binance';

            if ($isAutoScan) event(new MarketScanProgress("Fetching exchange data for {$symbol}...", 'info', $symbol));

            // ── Step 1: Technical Analysis ────────────────────────────────────
            // Always analyze the requested timeframe (used as LTF/entry timeframe)
            $taData = $this->taService->analyze($symbol, $exchange, $timeframe);

            // If MTF active, fetch HTF and MTF data as well (in parallel conceptually)
            $htfData = null;
            $mtfData = null;
            if ($mtfActive) {
                if ($isAutoScan) event(new MarketScanProgress("[MTF] Fetching {$htfTf} (Bias) + {$mtfTf} (Structure) data...", 'info', $symbol));
                $htfData = $this->taService->analyze($symbol, $exchange, $htfTf);
                $mtfData = $this->taService->analyze($symbol, $exchange, $mtfTf);
                $ltfTf   = $timeframe; // The clicked timeframe is the LTF entry timeframe
            }

            if ($isAutoScan) {
                $rsi   = is_array($taData['classical']['rsi'] ?? null) ? json_encode($taData['classical']['rsi']) : ($taData['classical']['rsi']['current'] ?? 'N/A');
                $trend = is_array($taData['overall_bias'] ?? null)     ? json_encode($taData['overall_bias'])     : ($taData['overall_bias'] ?? 'Neutral');
                $mode  = $mtfActive ? "[MTF: {$htfTf}→{$mtfTf}→{$timeframe}]" : "[Single TF]";
                event(new MarketScanProgress("Technical Analysis complete {$mode}. RSI: {$rsi} | Bias: {$trend}", 'info', $symbol));
            }

            // ── Step 2: Save primary (LTF) analysis to DB ─────────────────────
            $analysis = Analysis::create([
                'coin_id'              => $coin->id,
                'timeframe'            => $timeframe,
                'raw_data'             => $taData,
                'classical_data'       => $taData['classical'] ?? null,
                'smc_data'             => $taData['smc'] ?? null,
                'harmonic_data'        => $taData['harmonic'] ?? null,
                'volume_profile_data'  => $taData['volume_profile'] ?? null,
                'chart_overlays'       => $taData['chart_overlays'] ?? null,
                'overall_bias'         => $taData['overall_bias'] ?? null,
                'overall_confidence'   => $taData['overall_confidence'] ?? null,
                'confluences'          => $taData['confluences'] ?? [],
                'analyzed_at'          => now(),
            ]);

            // ── Step 3: News + Whale data ─────────────────────────────────────
            if ($isAutoScan) event(new MarketScanProgress("Checking global news & market sentiment...", 'info', $symbol));
            $news           = $this->newsService->getLatestNews('news', 10);
            $sentimentScore = $this->calculateSentimentScore($news, $symbol);

            if ($isAutoScan) event(new MarketScanProgress("Scanning for large whale transactions on {$symbol}...", 'info', $symbol));
            $whaleData      = $this->whaleService->getRecentTransactions();
            $relevantWhales = $this->filterWhalesBySymbol($whaleData, $symbol);

            if ($isAutoScan && count($relevantWhales) > 0) {
                $totalUsd    = array_sum(array_column($relevantWhales, 'amount_usd'));
                $deposits    = count(array_filter($relevantWhales, fn($w) => $w['transaction_type'] === 'exchange_deposit'));
                $withdrawals = count(array_filter($relevantWhales, fn($w) => $w['transaction_type'] === 'exchange_withdrawal'));
                $msg = "Found " . count($relevantWhales) . " massive whale movements! Total: $" . number_format($totalUsd, 2) . " (Deposits: {$deposits} | Withdrawals: {$withdrawals})";
                event(new MarketScanProgress($msg, 'warning', $symbol));
            }

            // ── Step 4: Build AI payload ──────────────────────────────────────
            if ($isAutoScan) {
                $mode = $mtfActive ? "[MTF Top-Down: {$htfTf}→{$mtfTf}→{$timeframe}]" : "[Single TF]";
                event(new MarketScanProgress("Sending data to Deep AI Engine {$mode}...", 'info', $symbol));
            }

            $aiData = array_merge($taData, [
                'news_sentiment' => $sentimentScore,
                'whale_activity' => $relevantWhales,
                'is_trending'    => $request->boolean('is_trending', false),
            ]);

            $aiProvider = $settings['ai_provider'] ?? '';

            // ── Step 5: Build prompt (MTF or single-TF) ───────────────────────
            if ($mtfActive) {
                $prompt = $this->aiService->buildMtfTradingPrompt(
                    $htfData, $htfTf,
                    $mtfData, $mtfTf,
                    $taData,  $timeframe,
                    $symbol,  $settings
                );
            } else {
                $prompt = $this->aiService->buildTradingPrompt($aiData, $symbol, $timeframe, $settings);
            }

            // ── Step 5.5: Antigravity bridge path ─────────────────────────────
            if ($aiProvider === 'antigravity') {
                $bridge = new AgentBridgeService($settings);
                if (!$bridge->isConfigured()) {
                    return response()->json(['message' => 'Antigravity bridge folder is not configured in settings.'], 400);
                }

                $systemPrompt = 'You are an expert crypto trading analyst. Always respond with valid JSON only. No markdown. No explanation.';
                $requestId    = $bridge->submitRequest(
                    type: 'analysis',
                    input: ['prompt' => $prompt],
                    promptContext: $systemPrompt
                );

                Cache::put("analysis_context_{$requestId}", [
                    'taData'         => $taData,
                    'sentimentScore' => $sentimentScore,
                    'relevantWhales' => $relevantWhales,
                    'mtf_active'     => $mtfActive,
                    'htf_tf'         => $htfTf,
                    'mtf_tf'         => $mtfTf,
                ], now()->addMinutes(15));

                return response()->json([
                    'bridge_pending' => true,
                    'request_id'     => $requestId,
                    'message'        => 'Analysis queued for Antigravity AI.',
                ]);
            }

            // ── Standard synchronous path ─────────────────────────────────────
            // Call AI with the pre-built prompt directly
            $aiResult = $this->aiService->generateRecommendationFromPrompt($prompt, $settings);
            $aiResult = $this->validateAndClampRecommendation($aiResult, $taData, $timeframe);

            if ($isAutoScan) {
                $aiAction  = is_array($aiResult['action'] ?? null)     ? json_encode($aiResult['action'])     : ($aiResult['action'] ?? 'WAIT');
                $aiConf    = is_array($aiResult['confidence'] ?? null)  ? json_encode($aiResult['confidence'])  : ($aiResult['confidence'] ?? 0);
                $reasoning = is_array($aiResult['reasoning'] ?? null)   ? json_encode($aiResult['reasoning'])   : ($aiResult['reasoning'] ?? '');
                $color = $aiAction === 'BUY' ? 'success' : ($aiAction === 'SELL' ? 'error' : 'warning');
                event(new MarketScanProgress("AI Output: [{$aiAction} - {$aiConf}%] " . $reasoning, $color, $symbol));
            }

            // Mark previous active recommendations as expired
            Recommendation::where('coin_id', $coin->id)
                ->where('timeframe', $timeframe)
                ->where('status', 'active')
                ->update(['status' => 'expired']);

            // ── Step 6: Save recommendation ───────────────────────────────────
            $recommendation = Recommendation::create([
                'coin_id'         => $coin->id,
                'analysis_id'     => $analysis->id,
                'timeframe'       => $timeframe,
                'action'          => $aiResult['action'] ?? 'WAIT',
                'entry_price'     => $aiResult['entry_price'] ?? $taData['current_price'],
                'tp1'             => $aiResult['tp1'] ?? null,
                'tp2'             => $aiResult['tp2'] ?? null,
                'tp3'             => $aiResult['tp3'] ?? null,
                'sl'              => $aiResult['sl'] ?? $taData['current_price'],
                'risk_reward'     => $aiResult['risk_reward'] ?? null,
                'confidence'      => $aiResult['confidence'] ?? 0,
                'reasoning'       => $aiResult['reasoning'] ?? null,
                'confluences'     => $aiResult['confluences'] ?? [],
                'invalidation'    => $aiResult['invalidation'] ?? null,
                'sentiment_score' => $sentimentScore,
                'whale_activity'  => $relevantWhales,
                'ai_model'        => $aiProvider === 'antigravity' ? 'Antigravity AI' : ($settings['analysis_model'] ?? env('OPENROUTER_DEFAULT_MODEL')),
                'mtf_mode'        => $mtfActive,
                'mtf_timeframes'  => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
                'status'          => 'active',
                'expires_at'      => now()->addHours($this->getExpiryHours($timeframe)),
            ]);

            if ($recommendation->action !== 'WAIT' && $recommendation->confidence >= 60) {
                event(new \App\Events\OpportunityCreated($recommendation));
            }

            $coin->update(['current_price' => $taData['current_price'] ?? $coin->current_price]);

            return response()->json([
                'analysis'        => $analysis,
                'recommendation'  => $recommendation,
                'chart_overlays'  => $taData['chart_overlays'] ?? [],
                'candles'         => $taData['candles'] ?? [],
                'mtf_active'      => $mtfActive,
                'mtf_timeframes'  => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
            ]);

        } catch (\Exception $e) {
            Log::error('Analysis failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Analysis failed: ' . $e->getMessage()], 500);
        }
    }


    public function bridgeStatus(Request $request, string $symbol, string $timeframe, string $requestId): JsonResponse
    {
        $symbol = strtoupper($symbol);
        $settingsCtrl = new SettingsController();
        $settings     = $settingsCtrl->getSettings();
        $bridge = new AgentBridgeService($settings);
        
        $status = $bridge->getStatus($requestId);

        if ($status['status'] === 'completed') {
            try {
                // Parse AI Result
                $cleaned = preg_replace('/```json|```/', '', $status['result'] ?? '{}');
                $cleaned = preg_replace('/(\d),(\d)/', '$1$2', $cleaned);
                $aiResult = json_decode(trim($cleaned), true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON from Antigravity AI: ' . $status['result']);
                }

                $cachedContext = Cache::get("analysis_context_{$requestId}");
                if (!$cachedContext) {
                    throw new \Exception('Analysis context expired from cache. Please run analysis again.');
                }

                $taData         = $cachedContext['taData'];
                $sentimentScore = $cachedContext['sentimentScore'];
                $relevantWhales = $cachedContext['relevantWhales'];
                $mtfActive      = $cachedContext['mtf_active'] ?? false;
                $htfTf          = $cachedContext['htf_tf'] ?? null;
                $mtfTf          = $cachedContext['mtf_tf'] ?? null;

                $coin = Coin::where('symbol', $symbol)->firstOrFail();
                $isAutoScan = $request->boolean('is_trending', false);

                // Re-run validation and saving logic
                $aiResult = $this->validateAndClampRecommendation($aiResult, $taData, $timeframe);
                
                if ($isAutoScan) {
                    $aiAction = is_array($aiResult['action'] ?? null) ? json_encode($aiResult['action']) : ($aiResult['action'] ?? 'WAIT');
                    $aiConf = is_array($aiResult['confidence'] ?? null) ? json_encode($aiResult['confidence']) : ($aiResult['confidence'] ?? 0);
                    $reasoning = is_array($aiResult['reasoning'] ?? null) ? json_encode($aiResult['reasoning']) : ($aiResult['reasoning'] ?? '');
                    $color = $aiAction === 'BUY' ? 'success' : ($aiAction === 'SELL' ? 'error' : 'warning');
                    event(new MarketScanProgress("AI Output: [{$aiAction} - {$aiConf}%] " . $reasoning, $color, $symbol));
                }

                $analysis = Analysis::create([
                    'coin_id'              => $coin->id,
                    'timeframe'            => $timeframe,
                    'raw_data'             => $taData,
                    'classical_data'       => $taData['classical'] ?? null,
                    'smc_data'             => $taData['smc'] ?? null,
                    'harmonic_data'        => $taData['harmonic'] ?? null,
                    'volume_profile_data'  => $taData['volume_profile'] ?? null,
                    'chart_overlays'       => $taData['chart_overlays'] ?? null,
                    'overall_bias'         => $taData['overall_bias'] ?? null,
                    'overall_confidence'   => $taData['overall_confidence'] ?? null,
                    'confluences'          => $taData['confluences'] ?? [],
                    'analyzed_at'          => now(),
                ]);

                Recommendation::where('coin_id', $coin->id)
                    ->where('timeframe', $timeframe)
                    ->where('status', 'active')
                    ->update(['status' => 'expired']);

                $recommendation = Recommendation::create([
                    'coin_id'         => $coin->id,
                    'analysis_id'     => $analysis->id,
                    'timeframe'       => $timeframe,
                    'action'          => $aiResult['action'] ?? 'WAIT',
                    'entry_price'     => $aiResult['entry_price'] ?? $taData['current_price'],
                    'tp1'             => $aiResult['tp1'] ?? null,
                    'tp2'             => $aiResult['tp2'] ?? null,
                    'tp3'             => $aiResult['tp3'] ?? null,
                    'sl'              => $aiResult['sl'] ?? $taData['current_price'],
                    'risk_reward'     => $aiResult['risk_reward'] ?? null,
                    'confidence'      => $aiResult['confidence'] ?? 0,
                    'reasoning'       => $aiResult['reasoning'] ?? null,
                    'confluences'     => $aiResult['confluences'] ?? [],
                    'invalidation'    => $aiResult['invalidation'] ?? null,
                    'sentiment_score' => $sentimentScore,
                    'whale_activity'  => $relevantWhales,
                    'ai_model'        => 'Antigravity AI',
                    'mtf_mode'        => $mtfActive,
                    'mtf_timeframes'  => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
                    'status'          => 'active',
                    'expires_at'      => now()->addHours($this->getExpiryHours($timeframe)),
                ]);

                if ($recommendation->action !== 'WAIT' && $recommendation->confidence >= 60) {
                    event(new \App\Events\OpportunityCreated($recommendation));
                }

                $coin->update(['current_price' => $taData['current_price'] ?? $coin->current_price]);

                Cache::forget("analysis_context_{$requestId}");

                return response()->json([
                    'status'         => 'completed',
                    'analysis'       => $analysis,
                    'recommendation' => $recommendation,
                    'chart_overlays' => $taData['chart_overlays'] ?? [],
                    'candles'        => $taData['candles'] ?? [],
                    'mtf_active'     => $mtfActive,
                    'mtf_timeframes' => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
                ]);
            } catch (\Exception $e) {
                Log::error('Bridge Analysis failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                return response()->json(['status' => 'failed', 'message' => 'Analysis failed: ' . $e->getMessage()]);
            }
        }

        return response()->json($status);
    }

    public function latest(Request $request, string $symbol, string $timeframe): JsonResponse
    {
        $symbol = strtoupper($symbol);
        $coin   = Coin::where('symbol', $symbol)->firstOrFail();

        $analysis = Analysis::where('coin_id', $coin->id)
            ->where('timeframe', $timeframe)
            ->latest('analyzed_at')
            ->first();

        if (!$analysis) {
            return response()->json(['message' => 'No analysis found. Run analysis first.'], 404);
        }

        $recommendation = Recommendation::where('analysis_id', $analysis->id)->latest()->first();

        return response()->json([
            'analysis'       => $analysis,
            'recommendation' => $recommendation,
            'chart_overlays' => $analysis->chart_overlays ?? [],
        ]);
    }

    private function calculateSentimentScore(array $news, string $symbol): float
    {
        if (empty($news)) return 0.0;

        $baseAsset = str_replace('USDT', '', $symbol);
        $relevant  = array_filter($news, fn($n) => in_array($baseAsset, $n['coins_mentioned'] ?? []));

        // No coin-specific news found — stay neutral rather than attributing unrelated
        // general market news sentiment to this specific coin's recommendation.
        if (empty($relevant)) {
            return 0.0;
        }

        $scores = array_map(fn($n) => $n['sentiment_score'] ?? 0, $relevant);
        return count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : 0.0;
    }

    private function filterWhalesBySymbol(array $whales, string $symbol): array
    {
        $baseAsset = str_replace('USDT', '', $symbol);
        return array_values(array_filter($whales, fn($w) => strtoupper($w['symbol'] ?? '') === $baseAsset));
    }

    /**
     * Validate and clamp AI-generated recommendation values to be mathematically sound.
     */
    private function validateAndClampRecommendation(array $aiResult, array $taData, string $timeframe): array
    {
        $settings       = $this->loadAiSettings();
        $minConfluences = (int) ($settings['min_confluences'] ?? 3);
        $minRiskReward  = (float) ($settings['min_risk_reward'] ?? 1.5);

        $action = strtoupper($aiResult['action'] ?? 'WAIT');
        $aiResult['action'] = $action;

        if (!in_array($action, ['BUY', 'SELL'])) {
            $aiResult['tp1'] = null;
            $aiResult['tp2'] = null;
            $aiResult['tp3'] = null;
            $aiResult['sl']  = null;
            $aiResult['risk_reward'] = 'N/A';
            return $aiResult;
        }

        // 0. Enforce the minimum confluence count server-side. The prompt asks the model
        // not to issue BUY/SELL below this threshold, but that's an instruction the model
        // could ignore or miscount — verify the count it actually reported, the same way
        // price/R:R below are verified rather than trusted.
        $confluenceCount = count($aiResult['confluences'] ?? []);
        if ($confluenceCount < $minConfluences) {
            Log::info('Recommendation downgraded to WAIT due to insufficient confluences', [
                'original_action' => $action, 'confluences_found' => $confluenceCount, 'required' => $minConfluences,
            ]);
            $aiResult['action'] = 'WAIT';
            $aiResult['tp1'] = null;
            $aiResult['tp2'] = null;
            $aiResult['tp3'] = null;
            $aiResult['sl']  = null;
            $aiResult['risk_reward'] = 'N/A';
            $aiResult['confidence'] = 0;
            $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') . " [Auto-downgraded: only {$confluenceCount} confluence(s) reported, below the required {$minConfluences}.]");
            return $aiResult;
        }

        $currentPrice = (float) ($taData['current_price'] ?? 0);
        if ($currentPrice <= 0) return $aiResult;

        // ── Guard: If current price is INSIDE a Bearish OB, never allow BUY ──
        if ($action === 'BUY') {
            $orderBlocks = $taData['smc']['order_blocks'] ?? [];
            foreach ($orderBlocks as $ob) {
                if (($ob['type'] ?? '') === 'bearish') {
                    $bottom = (float) ($ob['bottom'] ?? 0);
                    $top    = (float) ($ob['top']    ?? 0);
                    if ($bottom > 0 && $top > 0 && $currentPrice >= $bottom && $currentPrice <= $top) {
                        Log::info('BUY downgraded to WAIT: price is inside a Bearish Order Block', [
                            'symbol' => $taData['symbol'] ?? '?',
                            'price'  => $currentPrice,
                            'ob_bottom' => $bottom,
                            'ob_top'    => $top,
                        ]);
                        $aiResult['action']     = 'WAIT';
                        $aiResult['tp1']        = null;
                        $aiResult['tp2']        = null;
                        $aiResult['tp3']        = null;
                        $aiResult['sl']         = null;
                        $aiResult['risk_reward']= 'N/A';
                        $aiResult['confidence'] = 0;
                        $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
                            " [Auto-downgraded: Price (\${$currentPrice}) is inside a Bearish Order Block (\${$bottom}–\${$top}), a high-probability supply/rejection zone. Entry risk is extreme.]");
                        return $aiResult;
                    }
                }
            }
        }

        $constraints = $this->getTimeframeConstraints($timeframe);
        $entry = (float) ($aiResult['entry_price'] ?? $currentPrice);
        $tp1 = isset($aiResult['tp1']) ? (float) $aiResult['tp1'] : null;
        $tp2 = isset($aiResult['tp2']) ? (float) $aiResult['tp2'] : null;
        $tp3 = isset($aiResult['tp3']) ? (float) $aiResult['tp3'] : null;
        $sl  = isset($aiResult['sl'])  ? (float) $aiResult['sl']  : null;

        // 1. Clamp entry to within ±1% of current price
        $maxEntryDrift = $currentPrice * 0.01;
        if (abs($entry - $currentPrice) > $maxEntryDrift) {
            Log::warning('AI entry_price clamped', ['original' => $entry, 'current' => $currentPrice]);
            $entry = $currentPrice;
        }

        // 2. Validate and clamp TPs based on timeframe constraints
        $maxDistance = $currentPrice * $constraints['max_tp3'];
        $minStep = $currentPrice * $constraints['tp_step'] * 0.5;

        if ($action === 'BUY') {
            // Ensure TPs are above entry
            if ($tp1 !== null && $tp1 <= $entry) $tp1 = $entry + $minStep;
            if ($tp2 !== null && $tp2 <= $entry) $tp2 = $entry + $minStep * 2;
            if ($tp3 !== null && $tp3 <= $entry) $tp3 = $entry + $minStep * 3;

            // Clamp max distance
            $ceiling = $entry + $maxDistance;
            if ($tp1 !== null) $tp1 = min($tp1, $ceiling);
            if ($tp2 !== null) $tp2 = min($tp2, $ceiling);
            if ($tp3 !== null) $tp3 = min($tp3, $ceiling);

            // Ensure ordering: TP1 < TP2 < TP3
            if ($tp1 !== null && $tp2 !== null && $tp2 <= $tp1 + $minStep) $tp2 = $tp1 + $minStep;
            if ($tp2 !== null && $tp3 !== null && $tp3 <= $tp2 + $minStep) $tp3 = $tp2 + $minStep;

            // Validate SL is below entry
            if ($sl === null || $sl >= $entry) {
                $sl = $entry - ($currentPrice * $constraints['sl_ratio']);
            }
            // Clamp SL to not be too far
            $floor = $entry - $maxDistance;
            $sl = max($sl, $floor);

        } elseif ($action === 'SELL') {
            // Ensure TPs are below entry
            if ($tp1 !== null && $tp1 >= $entry) $tp1 = $entry - $minStep;
            if ($tp2 !== null && $tp2 >= $entry) $tp2 = $entry - $minStep * 2;
            if ($tp3 !== null && $tp3 >= $entry) $tp3 = $entry - $minStep * 3;

            // Clamp max distance
            $floor = $entry - $maxDistance;
            if ($tp1 !== null) $tp1 = max($tp1, $floor);
            if ($tp2 !== null) $tp2 = max($tp2, $floor);
            if ($tp3 !== null) $tp3 = max($tp3, $floor);

            // Ensure ordering: TP1 > TP2 > TP3
            if ($tp1 !== null && $tp2 !== null && $tp2 >= $tp1 - $minStep) $tp2 = $tp1 - $minStep;
            if ($tp2 !== null && $tp3 !== null && $tp3 >= $tp2 - $minStep) $tp3 = $tp2 - $minStep;

            // Validate SL is above entry
            if ($sl === null || $sl <= $entry) {
                $sl = $entry + ($currentPrice * $constraints['sl_ratio']);
            }
            // Clamp SL to not be too far
            $ceiling = $entry + $maxDistance;
            $sl = min($sl, $ceiling);
        }

        // 3. Recalculate R:R mathematically using TP2 as primary target
        $risk = abs($entry - $sl);
        $reward = ($tp2 !== null) ? abs($tp2 - $entry) : 0;
        $rrValue = ($risk > 0) ? round($reward / $risk, 2) : 0;
        $riskReward = "1:{$rrValue}";

        // 4. If R:R below the configured minimum after clamping, downgrade to WAIT
        if ($rrValue < $minRiskReward) {
            Log::info('Recommendation downgraded to WAIT due to poor R:R', [
                'original_action' => $action, 'rr' => $rrValue, 'required' => $minRiskReward,
            ]);
            $aiResult['action'] = 'WAIT';
            $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') . " [Auto-downgraded: R:R ratio ({$riskReward}) below minimum 1:{$minRiskReward} threshold after price validation.]");
            $aiResult['confidence'] = 0;
            return $aiResult;
        }

        // 5. Reconcile the model's self-reported confidence with the technical confidence
        // Python already computed deterministically from weighted TA-school agreement —
        // otherwise the two numbers can diverge wildly and the user sees two different
        // "confidence" figures for the same analysis.
        $aiResult['confidence'] = $this->reconcileConfidence((float) ($aiResult['confidence'] ?? 0), $taData);

        // Apply validated values
        $aiResult['entry_price'] = round($entry, 8);
        $aiResult['tp1'] = $tp1 !== null ? round($tp1, 8) : null;
        $aiResult['tp2'] = $tp2 !== null ? round($tp2, 8) : null;
        $aiResult['tp3'] = $tp3 !== null ? round($tp3, 8) : null;
        $aiResult['sl']  = round($sl, 8);
        $aiResult['risk_reward'] = $riskReward;

        return $aiResult;
    }

    /**
     * Clamp the AI's self-reported confidence to stay within a reasonable band of the
     * Python TA engine's independently computed overall_confidence, so the two "confidence"
     * numbers shown across the app (technical analysis block vs. recommendation) don't
     * contradict each other by a wide margin.
     */
    private function reconcileConfidence(float $aiConfidence, array $taData): int
    {
        $computedConfidence = (float) ($taData['overall_confidence'] ?? 0);
        if ($computedConfidence <= 0) {
            return (int) round($aiConfidence);
        }

        $maxDivergence = 25; // percentage points
        $lower = max(0, $computedConfidence - $maxDivergence);
        $upper = min(100, $computedConfidence + $maxDivergence);

        if ($aiConfidence < $lower || $aiConfidence > $upper) {
            Log::info('AI confidence clamped toward computed technical confidence', [
                'ai_confidence' => $aiConfidence, 'computed_confidence' => $computedConfidence,
            ]);
            $aiConfidence = max($lower, min($upper, $aiConfidence));
        }

        return (int) round($aiConfidence);
    }

    private function loadAiSettings(): array
    {
        $settingsFile = storage_path('app/ai_settings.json');
        if (!file_exists($settingsFile)) {
            return [];
        }
        return json_decode(file_get_contents($settingsFile), true) ?? [];
    }

    /**
     * Get timeframe-specific distance constraints for validation.
     */
    private function getTimeframeConstraints(string $timeframe): array
    {
        return match ($timeframe) {
            '1m', '5m'   => ['max_tp3' => 0.02,   'tp_step' => 0.005,  'sl_ratio' => 0.008],
            '15m', '30m' => ['max_tp3' => 0.04,   'tp_step' => 0.01,   'sl_ratio' => 0.015],
            '1h'         => ['max_tp3' => 0.08,   'tp_step' => 0.02,   'sl_ratio' => 0.025],
            '4h'         => ['max_tp3' => 0.15,   'tp_step' => 0.04,   'sl_ratio' => 0.05],
            '1d'         => ['max_tp3' => 0.25,   'tp_step' => 0.08,   'sl_ratio' => 0.08],
            '1w'         => ['max_tp3' => 0.50,   'tp_step' => 0.15,   'sl_ratio' => 0.15],
            default      => ['max_tp3' => 0.15,   'tp_step' => 0.04,   'sl_ratio' => 0.05],
        };
    }

    private function getExpiryHours(string $timeframe): int
    {
        return match ($timeframe) {
            '1m', '5m'         => 1,
            '15m', '30m'       => 4,
            '1h'               => 8,
            '4h'               => 24,
            '1d'               => 72,
            '1w'               => 168,
            default            => 24,
        };
    }
}
