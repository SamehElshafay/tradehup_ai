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
use App\Services\PreTradeFilterService;
use App\Services\AmdCycleService;
use App\Http\Controllers\Api\SettingsController;
use App\Events\MarketScanProgress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AnalysisController extends Controller
{
    public function __construct(
        private PythonTAService       $taService,
        private OpenRouterService     $aiService,
        private CryptoPanicService    $newsService,
        private WhaleAlertService     $whaleService,
        private PreTradeFilterService $preTradeFilter,
        private AmdCycleService       $amdCycleService,
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

            if ($mtfActive) {
                // Strict enforce MTF structure: ignore the single clicked timeframe from the UI route
                // and use the configured LTF as the base entry timeframe.
                $timeframe = $ltfTf;
            }

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
                
                // Inject into taData so it's saved in the raw_data JSON for the report
                $taData['htf_data'] = $htfData;
                $taData['mtf_data'] = $mtfData;
            }

            if ($isAutoScan) {
                $rsi   = is_array($taData['classical']['rsi'] ?? null) ? json_encode($taData['classical']['rsi']) : ($taData['classical']['rsi']['current'] ?? 'N/A');
                $trend = is_array($taData['overall_bias'] ?? null)     ? json_encode($taData['overall_bias'])     : ($taData['overall_bias'] ?? 'Neutral');
                $mode  = $mtfActive ? "[MTF: {$htfTf}→{$mtfTf}→{$timeframe}]" : "[Single TF]";
                event(new MarketScanProgress("Technical Analysis complete {$mode}. RSI: {$rsi} | Bias: {$trend}", 'info', $symbol));
            }

            // Update coin's volume_24h and price if available
            if (isset($taData['volume_24h'])) {
                $coin->volume_24h = $taData['volume_24h'];
            }
            if (isset($taData['current_price'])) {
                $coin->current_price = $taData['current_price'];
            }
            $coin->save();

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

            if ($isAutoScan) event(new MarketScanProgress("Scanning for recent specific news on {$symbol}...", 'info', $symbol));
            $specificNews = $this->newsService->getCoinSpecificNews($symbol);
            if (!empty($specificNews)) {
                if (!isset($taData['confluences'])) {
                    $taData['confluences'] = [];
                }
                foreach ($specificNews as $article) {
                    $taData['confluences'][] = "📰 24h News: [".ucfirst($article['sentiment'])."] " . $article['title'];
                }
                // Store structured news in raw_data so reportGenerator can read it
                $taData['specific_news'] = $specificNews;
                $analysis->update([
                    'raw_data'    => $taData,
                    'confluences' => $taData['confluences']
                ]);
            }

            // ── Step 3.5: Session Context ─────────────────────────────────────
            $sessionContext = $this->buildSessionContext();
            $taData['session_context'] = $sessionContext;

            if ($isAutoScan && $sessionContext['is_noisy']) {
                event(new MarketScanProgress(
                    "⏰ Session Warning: " . $sessionContext['session_alert'] . " — AI is aware.",
                    'warning',
                    $symbol
                ));
            }

            // ── Step 3.55: AMD Cycle Analysis (Accumulation-Manipulation-Distribution) ──
            // Purely additive — a coin with no valid accumulation zone or no manipulation
            // detected simply gets status 'no_data'/'no_manipulation' and everything else
            // (prompt, filters, report) proceeds exactly as it would without this layer.
            $atrForAmd  = (float) ($taData['classical']['atr']['current'] ?? 0);
            $amdAnalysis = $this->amdCycleService->analyze($symbol, $exchange, $atrForAmd, $settings);
            $taData['amd_analysis'] = $amdAnalysis;

            if ($isAutoScan && $amdAnalysis['manipulation_detected']) {
                event(new MarketScanProgress(
                    "🎯 AMD Cycle: " . $amdAnalysis['summary'],
                    'warning',
                    $symbol
                ));
            }

            // ── Step 3.6: Pre-Trade Filters ───────────────────────────────────
            if ($isAutoScan) event(new MarketScanProgress("Running pre-trade quality filters...", 'info', $symbol));

            $filterResult = $this->preTradeFilter->run($taData, $settings);

            // Inject filter warnings into confluences so the AI prompt sees them
            if (!empty($filterResult['warnings'])) {
                $taData['confluences'] = array_merge($taData['confluences'] ?? [], $filterResult['warnings']);
            }

            // Store filter result and updated data in raw_data for the report
            $taData['pre_trade_filter'] = $filterResult;
            $analysis->update(['confluences' => $taData['confluences'], 'raw_data' => $taData]);

            if ($isAutoScan && $filterResult['pre_trade_verdict'] === 'WAIT') {
                event(new MarketScanProgress(
                    "Pre-Trade Filter → WAIT: " . ($filterResult['pre_trade_reason'] ?? 'Quality gate not met.'),
                    'warning',
                    $symbol
                ));
            }

            // ── Step 3.7: Skip the AI call entirely on a deterministic filter WAIT ──
            // When enabled, a pre-trade filter WAIT is never sent to the AI — it would
            // just get overridden back to WAIT by applyPreTradeFilterOverride() below,
            // so calling the AI first only burns tokens for a discarded result.
            $skipAiOnWait = (bool) ($settings['skip_ai_on_filter_wait'] ?? false);
            $aiProvider   = $settings['ai_provider'] ?? '';

            if ($skipAiOnWait && ($filterResult['pre_trade_verdict'] ?? 'PROCEED') === 'WAIT') {
                if ($isAutoScan) {
                    event(new MarketScanProgress(
                        "Pre-Trade Filter → WAIT. Skipping AI call to save tokens (skip_ai_on_filter_wait is on).",
                        'warning',
                        $symbol
                    ));
                }

                $aiResult = [
                    'action'       => 'WAIT',
                    'entry_price'  => $taData['current_price'] ?? null,
                    'tp1'          => null,
                    'tp2'          => null,
                    'tp3'          => null,
                    'sl'           => null,
                    'risk_reward'  => 'N/A',
                    'confidence'   => $filterResult['adjusted_confidence'] ?? 0,
                    'reasoning'    => '[Pre-Trade Filter: ' . ($filterResult['pre_trade_reason'] ?? 'Quality gate not met.') . '] (AI call skipped to save tokens)',
                    'confluences'  => $taData['confluences'] ?? [],
                    'invalidation' => null,
                    'sl_auto_widened' => false,
                ];
            } else {
                // ── Step 4: Build AI payload ──────────────────────────────────────
                if ($isAutoScan) {
                    $mode = $mtfActive ? "[MTF Top-Down: {$htfTf}→{$mtfTf}→{$timeframe}]" : "[Single TF]";
                    event(new MarketScanProgress("Sending data to Deep AI Engine {$mode}...", 'info', $symbol));
                }

                $aiData = array_merge($taData, [
                    'news_sentiment'       => $sentimentScore,
                    'whale_activity'       => $relevantWhales,
                    'specific_news'        => $specificNews,
                    'is_trending'          => $request->boolean('is_trending', false),
                    'pre_trade_filter'     => $filterResult,
                    'session_context'      => $sessionContext,
                    'amd_analysis'         => $amdAnalysis,
                ]);

                // ── Step 5: Build prompt (MTF or single-TF) ───────────────────────
                if ($mtfActive) {
                    $prompt = $this->aiService->buildMtfTradingPrompt(
                        $htfData, $htfTf,
                        $mtfData, $mtfTf,
                        $aiData,  $timeframe,
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
                        'ltf_tf'         => $ltfTf,
                        'filter_result'  => $filterResult,
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

                // ── Pre-Trade Filter Hard Override ────────────────────────────────
                // Even if the AI says BUY/SELL, the filter verdict can force WAIT.
                $aiResult = $this->applyPreTradeFilterOverride($aiResult, $filterResult);
            }

            // ⚡ DETERMINISM FIX — Layer 3: Consistency check
            // Compare this result with the last signal for the same symbol/TF.
            // Injects a warning if the action changed within 5 minutes and price barely moved (< 0.1%).
            $currentPrice = (float)($taData['current_price'] ?? 0);
            $consistencyWarning = $this->checkConsistency($symbol, $timeframe, $currentPrice, $aiResult);
            if ($consistencyWarning) {
                $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') . " {$consistencyWarning}");
            }

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
                'strategy'        => $request->input('strategy'),
                'mtf_mode'        => $mtfActive,
                'mtf_timeframes'  => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
                'status'          => 'active',
                'expires_at'      => now()->addHours($this->getExpiryHours($timeframe)),
            ]);

            if ($recommendation->action !== 'WAIT' && $recommendation->confidence >= 60) {
                event(new \App\Events\OpportunityCreated($recommendation));
            }

            $coin->update(['current_price' => $taData['current_price'] ?? $coin->current_price]);

            // Append ATR/SL gate metadata to the recommendation for the frontend
            // (not stored in DB — returned in-memory for this response only)
            $recommendationData = $recommendation->toArray();
            $recommendationData['sl_auto_widened'] = $aiResult['sl_auto_widened'] ?? false;
            $recommendationData['sl_original']     = $aiResult['sl_original']     ?? null;
            $recommendationData['sl_min_atr_dist'] = $aiResult['sl_min_atr_dist'] ?? null;

            return response()->json([
                'analysis'           => $analysis,
                'recommendation'     => $recommendationData,
                'chart_overlays'     => $taData['chart_overlays'] ?? [],
                'candles'            => $taData['candles'] ?? [],
                'mtf_active'         => $mtfActive,
                'mtf_timeframes'     => $mtfActive ? "{$htfTf}→{$mtfTf}→{$timeframe}" : null,
                'consistency_warning'=> $consistencyWarning ?? null,
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
                $ltfTf          = $cachedContext['ltf_tf'] ?? $timeframe;

                $coin = Coin::where('symbol', $symbol)->firstOrFail();
                $isAutoScan = $request->boolean('is_trending', false);

                // Re-run validation and saving logic
                $aiResult = $this->validateAndClampRecommendation($aiResult, $taData, $timeframe);

                // Apply pre-trade filter override (loaded from cache)
                $cachedFilterResult = $cachedContext['filter_result'] ?? null;
                if ($cachedFilterResult) {
                    $aiResult = $this->applyPreTradeFilterOverride($aiResult, $cachedFilterResult);
                }

                if ($isAutoScan) {
                    $aiAction = is_array($aiResult['action'] ?? null) ? json_encode($aiResult['action']) : ($aiResult['action'] ?? 'WAIT');
                    $aiConf = is_array($aiResult['confidence'] ?? null) ? json_encode($aiResult['confidence']) : ($aiResult['confidence'] ?? 0);
                    $reasoning = is_array($aiResult['reasoning'] ?? null) ? json_encode($aiResult['reasoning']) : ($aiResult['reasoning'] ?? '');
                    $color = $aiAction === 'BUY' ? 'success' : ($aiAction === 'SELL' ? 'error' : 'warning');
                    event(new MarketScanProgress("AI Output: [{$aiAction} - {$aiConf}%] " . $reasoning, $color, $symbol));
                }

                $analysis = Analysis::create([
                    'coin_id'              => $coin->id,
                    'timeframe'            => $ltfTf,
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
                    'timeframe'       => $ltfTf,
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
                    'strategy'        => $request->input('strategy'),
                    'mtf_mode'        => $mtfActive,
                    'mtf_timeframes'  => $mtfActive ? "{$htfTf}→{$mtfTf}→{$ltfTf}" : null,
                    'status'          => 'active',
                    'expires_at'      => now()->addHours($this->getExpiryHours($timeframe)),
                ]);

                if ($recommendation->action !== 'WAIT' && $recommendation->confidence >= 60) {
                    event(new \App\Events\OpportunityCreated($recommendation));
                }

                $coin->update(['current_price' => $taData['current_price'] ?? $coin->current_price]);

                Cache::forget("analysis_context_{$requestId}");

                // Append ATR/SL gate metadata to the recommendation for the frontend
                $bridgeRecData = $recommendation->toArray();
                $bridgeRecData['sl_auto_widened'] = $aiResult['sl_auto_widened'] ?? false;
                $bridgeRecData['sl_original']     = $aiResult['sl_original']     ?? null;
                $bridgeRecData['sl_min_atr_dist'] = $aiResult['sl_min_atr_dist'] ?? null;

                return response()->json([
                    'status'         => 'completed',
                    'analysis'       => $analysis,
                    'recommendation' => $bridgeRecData,
                    'chart_overlays' => $taData['chart_overlays'] ?? [],
                    'candles'        => $taData['candles'] ?? [],
                    'mtf_active'     => $mtfActive,
                    'mtf_timeframes' => $mtfActive ? "{$htfTf}→{$mtfTf}→{$ltfTf}" : null,
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

        $query = Analysis::where('coin_id', $coin->id);
        
        if ($timeframe !== 'all') {
            $query->where('timeframe', $timeframe);
        }
        
        $analysis = $query->latest('analyzed_at')->first();

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

    /**
     * Compute current trading session context based on UTC time.
     * Returns session names, overlap flags, and whether conditions are noisy/low-liquidity.
     */
    private function buildSessionContext(): array
    {
        $utcH = (int) gmdate('G');
        $utcM = (int) gmdate('i');
        $utcDecimal = $utcH + $utcM / 60;

        $activeSessions = [];
        if ($utcDecimal >= 0  && $utcDecimal < 9)  $activeSessions[] = 'Asian';
        if ($utcDecimal >= 7  && $utcDecimal < 16) $activeSessions[] = 'London';
        if ($utcDecimal >= 12 && $utcDecimal < 21) $activeSessions[] = 'New York';

        $londonNyOverlap   = $utcDecimal >= 12 && $utcDecimal < 16;
        $asiaLondonOverlap = $utcDecimal >= 7  && $utcDecimal < 9;
        $isDeadZone        = $utcDecimal >= 21 || $utcDecimal < 0;

        // Noisy: session open first hour or dead zone
        $isAsiaOpen    = $utcDecimal >= 0  && $utcDecimal < 1;
        $isLondonOpen  = $utcDecimal >= 7  && $utcDecimal < 8;
        $isNyOpen      = $utcDecimal >= 12 && $utcDecimal < 13;
        $isNoisy       = $isDeadZone || $isAsiaOpen || $isLondonOpen || $isNyOpen;

        $sessionAlert = '';
        $tradeFilter  = 'PROCEED';

        if ($londonNyOverlap) {
            $sessionAlert = 'London–NY Overlap (12:00–16:00 UTC) — highest liquidity. Best for breakouts.';
            $tradeFilter  = 'OPTIMAL';
        } elseif ($asiaLondonOverlap) {
            $sessionAlert = 'Asia–London transition (07:00–09:00 UTC) — stop-hunt candles common. Wait 30m.';
            $tradeFilter  = 'CAUTION';
        } elseif ($isDeadZone) {
            $sessionAlert = 'Post-NY Dead Zone (21:00–00:00 UTC) — very low liquidity. Avoid new entries.';
            $tradeFilter  = 'AVOID';
        } elseif ($isAsiaOpen) {
            $sessionAlert = 'Asia Open (00:00–01:00 UTC) — initial volatility spike. Wait for direction.';
            $tradeFilter  = 'CAUTION';
        } elseif ($isLondonOpen) {
            $sessionAlert = 'London Open (07:00–08:00 UTC) — stop-hunt wicks common. Wait for confirmation.';
            $tradeFilter  = 'CAUTION';
        } elseif ($isNyOpen) {
            $sessionAlert = 'NY Open (12:00–13:00 UTC) — high volatility. Wait 30m for direction.';
            $tradeFilter  = 'CAUTION';
        }

        return [
            'utc_time'           => gmdate('H:i') . ' UTC',
            'active_sessions'    => empty($activeSessions) ? ['Off-hours'] : $activeSessions,
            'london_ny_overlap'  => $londonNyOverlap,
            'asia_london_overlap'=> $asiaLondonOverlap,
            'is_dead_zone'       => $isDeadZone,
            'is_noisy'           => $isNoisy,
            'session_alert'      => $sessionAlert,
            'trade_filter'       => $tradeFilter,
        ];
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
     * Hard-override the AI result if the pre-trade filter says WAIT.
     * Lowers confidence to the adjusted score and clears trade levels.
     */
    private function applyPreTradeFilterOverride(array $aiResult, array $filterResult): array
    {
        // Always apply the penalty-adjusted confidence — even on PROCEED
        $adjustedConfidence = $filterResult['adjusted_confidence'] ?? null;
        if ($adjustedConfidence !== null && isset($aiResult['confidence'])) {
            // Use whichever is lower: AI reported or filter-adjusted
            $aiResult['confidence'] = min((int) $aiResult['confidence'], (int) $adjustedConfidence);
        }

        if (($filterResult['pre_trade_verdict'] ?? 'PROCEED') !== 'WAIT') {
            return $aiResult;
        }

        // AI wanted BUY/SELL but filter says WAIT — override
        if (in_array($aiResult['action'] ?? '', ['BUY', 'SELL'])) {
            $reason = $filterResult['pre_trade_reason'] ?? 'Pre-trade filter quality gate not met.';
            Log::info('Pre-Trade Filter hard-override: AI action downgraded to WAIT', [
                'original_action' => $aiResult['action'],
                'filter_reason'   => $reason,
            ]);
            $aiResult['action']     = 'WAIT';
            $aiResult['tp1']        = null;
            $aiResult['tp2']        = null;
            $aiResult['tp3']        = null;
            $aiResult['sl']         = null;
            $aiResult['risk_reward']= 'N/A';
            $aiResult['confidence'] = $filterResult['adjusted_confidence'] ?? 0;
            $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
                " [Pre-Trade Filter Override: {$reason}]");
        }

        return $aiResult;
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

        // ── Guard: price INSIDE a Bearish OB — penalize, don't hard-block BUY ──
        // Used to force WAIT outright. A real-data check (python-ta-service/services/
        // ob_rule_validator.py, 747 bullish signals / 5 symbols) found the real win
        // rate inside a bearish OB (94.7%, n=57 resolved) was statistically the same
        // as outside one (96.7%, n=395 resolved) once the tuned TP/SL/expiry from
        // trade_optimizer.py are used — the hard block was rejecting winners at the
        // same rate as losers. Downgraded to a modest confidence penalty instead.
        if ($action === 'BUY') {
            $orderBlocks = $taData['smc']['order_blocks'] ?? [];
            foreach ($orderBlocks as $ob) {
                if (($ob['type'] ?? '') === 'bearish') {
                    $bottom = (float) ($ob['bottom'] ?? 0);
                    $top    = (float) ($ob['top']    ?? 0);
                    if ($bottom > 0 && $top > 0 && $currentPrice >= $bottom && $currentPrice <= $top) {
                        Log::info('BUY confidence penalized: price is inside a Bearish Order Block', [
                            'symbol' => $taData['symbol'] ?? '?',
                            'price'  => $currentPrice,
                            'ob_bottom' => $bottom,
                            'ob_top'    => $top,
                        ]);
                        $aiResult['confidence'] = max(0, (int) ($aiResult['confidence'] ?? 0) - 10);
                        $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
                            " [Note: Price (\${$currentPrice}) is inside a Bearish Order Block (\${$bottom}–\${$top}) — confidence penalized 10%, not auto-rejected (real-data check found no significant win-rate difference here).]");
                        break;
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

        // 3. ATR/SL Backend Validation (Layer 2 of SL protection)
        // ── The Pre-Trade filter (Layer 1) already blocked trades where ATR data was
        //    clearly insufficient. Here we validate the ACTUAL SL the AI returned.
        //    If the AI's SL is tighter than 1.5× ATR, we AUTO-WIDEN it to the minimum
        //    safe distance rather than silently passing a stop-hunt trade.
        $atrValue = (float) ($taData['classical']['atr']['current'] ?? 0);
        $aiResult['sl_auto_widened'] = false;
        if ($atrValue > 0 && $sl !== null && $entry > 0) {
            $minSlDistance = $atrValue * 1.5;
            $actualSlDistance = abs($entry - $sl);

            if ($actualSlDistance < $minSlDistance) {
                $originalSl   = $sl;
                $wideSl       = ($action === 'BUY')
                    ? round($entry - $minSlDistance, 8)
                    : round($entry + $minSlDistance, 8);

                Log::info('ATR/SL Gate: AI SL too tight — auto-widened', [
                    'symbol'          => $taData['symbol'] ?? '?',
                    'action'          => $action,
                    'entry'           => $entry,
                    'original_sl'     => $originalSl,
                    'widened_sl'      => $wideSl,
                    'atr'             => $atrValue,
                    'min_sl_distance' => $minSlDistance,
                    'actual_distance' => $actualSlDistance,
                ]);

                $sl = $wideSl;
                $aiResult['sl_auto_widened']  = true;
                $aiResult['sl_original']      = round($originalSl, 8);
                $aiResult['sl_min_atr_dist']  = round($minSlDistance, 8);
                $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') .
                    " [⚠️ SL معدّل تلقائياً: SL الأصلي (" . round($originalSl, 8) . ") كان أضيق من 1.5× ATR (" . round($minSlDistance, 8) . "). تم التوسيع إلى " . round($wideSl, 8) . " لتجنب stop-hunt.]");
            }
        }

        // 4. Recalculate R:R mathematically using TP2 as primary target
        $risk = abs($entry - $sl);
        $reward = ($tp2 !== null) ? abs($tp2 - $entry) : 0;
        $rrValue = ($risk > 0) ? round($reward / $risk, 2) : 0;
        $riskReward = "1:{$rrValue}";

        // 5. If R:R below the configured minimum after clamping—
        //    ⚡ DETERMINISM FIX (Layer 2): instead of immediately downgrading to WAIT
        //    (which caused identical market conditions to produce SELL one run and WAIT another
        //    just because the AI chose slightly different TP numbers), we first try to
        //    recompute TP/SL deterministically from real S/R + OB + ATR data.
        //    Only fall through to WAIT if even the deterministic fallback can't achieve minRR.
        if ($rrValue < $minRiskReward) {
            $detLevels = $this->computeDeterministicLevels($action, $entry, $taData, $constraints);

            if ($detLevels !== null && $detLevels['rr_value'] >= $minRiskReward) {
                // Deterministic fallback succeeded — use its levels
                $tp1 = $detLevels['tp1'];
                $tp2 = $detLevels['tp2'];
                $tp3 = $detLevels['tp3'];
                $sl  = $detLevels['sl'];
                $rrValue    = $detLevels['rr_value'];
                $riskReward = $detLevels['risk_reward'];
                $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') .
                    " [TP/SL recalculated by deterministic fallback: AI produced R:R 1:{$rrValue}, PHP engine achieved 1:{$detLevels['rr_value']} using real S/R levels.]");
            } else {
                // Even deterministic fallback failed — now downgrade to WAIT
                Log::info('Recommendation downgraded to WAIT: R:R below minimum even after deterministic recalculation', [
                    'original_action' => $action,
                    'ai_rr'           => $rrValue,
                    'det_rr'          => $detLevels['rr_value'] ?? 'N/A',
                    'required'        => $minRiskReward,
                ]);
                $aiResult['action']     = 'WAIT';
                $aiResult['tp1']        = null;
                $aiResult['tp2']        = null;
                $aiResult['tp3']        = null;
                $aiResult['sl']         = null;
                $aiResult['risk_reward']= 'N/A';
                $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
                    " [Auto-downgraded: R:R ratio ({$riskReward}) below minimum 1:{$minRiskReward} threshold even after deterministic TP/SL recalculation.]");
                $aiResult['confidence'] = 0;
                return $aiResult;
            }
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

    // ─────────────────────────────────────────────────────────────────────────
    //  ⚡ DETERMINISM FIX — Layer 2: Deterministic TP/SL Fallback
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute TP1/TP2/TP3/SL entirely from deterministic PHP logic using real
     * S/R levels, Order Blocks, Volume Profile, and ATR data already computed
     * by the Python TA engine.
     *
     * Called when the AI's own TP/SL produce an R:R below the minimum threshold.
     * Returns null only if no usable price levels exist in the TA data at all.
     *
     * @param  string $action      'BUY' | 'SELL'
     * @param  float  $price       current price
     * @param  array  $taData      full TA payload from Python engine
     * @param  array  $constraints timeframe constraints (max_tp3, tp_step, sl_ratio)
     * @return array|null          { tp1, tp2, tp3, sl, risk_reward, rr_value } or null
     */
    private function computeDeterministicLevels(
        string $action,
        float  $price,
        array  $taData,
        array  $constraints
    ): ?array {
        if ($price <= 0) return null;

        $supports    = collect($taData['classical']['support_resistance']['support']    ?? [])
                         ->pluck('price')->map(fn($p) => (float)$p)->sort()->values()->toArray();
        $resistances = collect($taData['classical']['support_resistance']['resistance'] ?? [])
                         ->pluck('price')->map(fn($p) => (float)$p)->sort()->values()->toArray();
        $orderBlocks = $taData['smc']['order_blocks']    ?? [];
        $fvgs        = $taData['smc']['fair_value_gaps'] ?? [];
        $poc         = (float) ($taData['volume_profile']['key_levels']['poc'] ?? 0);
        $vah         = (float) ($taData['volume_profile']['key_levels']['vah'] ?? 0);
        $val         = (float) ($taData['volume_profile']['key_levels']['val'] ?? 0);
        $atr         = (float) ($taData['classical']['atr']['current'] ?? 0);

        $maxDist = $price * $constraints['max_tp3'];
        $slRatio = $constraints['sl_ratio'];
        $minStep = $price * $constraints['tp_step'] * 0.5;

        // Helper: collect candidate TP levels from multiple sources
        $buildCandidates = function (bool $above) use (
            $price, $maxDist, $supports, $resistances, $orderBlocks, $fvgs, $poc, $vah, $val
        ): array {
            $levels = [];

            // Classical S/R
            foreach ($above ? $resistances : $supports as $p) {
                if ($above && $p > $price && $p <= $price + $maxDist) $levels[] = (float)$p;
                if (!$above && $p < $price && $p >= $price - $maxDist) $levels[] = (float)$p;
            }

            // Order Block midpoints
            foreach ($orderBlocks as $ob) {
                $mid = ((float)($ob['bottom'] ?? 0) + (float)($ob['top'] ?? 0)) / 2;
                if ($mid <= 0) continue;
                if ($above && $mid > $price && $mid <= $price + $maxDist) $levels[] = $mid;
                if (!$above && $mid < $price && $mid >= $price - $maxDist) $levels[] = $mid;
            }

            // FVG midpoints
            foreach ($fvgs as $fvg) {
                $mid = ((float)($fvg['bottom'] ?? 0) + (float)($fvg['top'] ?? 0)) / 2;
                if ($mid <= 0) continue;
                if ($above && $mid > $price && $mid <= $price + $maxDist) $levels[] = $mid;
                if (!$above && $mid < $price && $mid >= $price - $maxDist) $levels[] = $mid;
            }

            // Volume Profile
            foreach ([$poc, $vah, $val] as $vp) {
                if ($vp <= 0) continue;
                if ($above && $vp > $price && $vp <= $price + $maxDist) $levels[] = $vp;
                if (!$above && $vp < $price && $vp >= $price - $maxDist) $levels[] = $vp;
            }

            return $above ? array_unique(array_values(array_filter($levels)))
                          : array_unique(array_values(array_filter($levels)));
        };

        if ($action === 'SELL') {
            // TP candidates: levels BELOW current price (sorted descending: closest first)
            $tpCandidates = $buildCandidates(false);
            rsort($tpCandidates);

            // SL: first level ABOVE price from OB/resistance + ATR buffer
            $slCandidates = [];
            foreach ($resistances as $p) {
                if ($p > $price && $p <= $price + $maxDist) $slCandidates[] = (float)$p;
            }
            foreach ($orderBlocks as $ob) {
                if (strtolower($ob['type'] ?? '') === 'bearish') {
                    $top = (float)($ob['top'] ?? 0);
                    if ($top > $price && $top <= $price + $maxDist) $slCandidates[] = $top;
                }
            }
            sort($slCandidates);
            $atrBuffer = max($atr * 1.5, $price * $slRatio * 0.5);
            $sl = !empty($slCandidates)
                ? min($slCandidates[0] + $atrBuffer, $price + $maxDist)
                : $price + ($price * $slRatio);

        } else { // BUY
            // TP candidates: levels ABOVE current price (sorted ascending: closest first)
            $tpCandidates = $buildCandidates(true);
            sort($tpCandidates);

            // SL: first level BELOW price from OB/support + ATR buffer
            $slCandidates = [];
            foreach ($supports as $p) {
                if ($p < $price && $p >= $price - $maxDist) $slCandidates[] = (float)$p;
            }
            foreach ($orderBlocks as $ob) {
                if (strtolower($ob['type'] ?? '') === 'bullish') {
                    $bottom = (float)($ob['bottom'] ?? 0);
                    if ($bottom < $price && $bottom >= $price - $maxDist) $slCandidates[] = $bottom;
                }
            }
            rsort($slCandidates);
            $atrBuffer = max($atr * 1.5, $price * $slRatio * 0.5);
            $sl = !empty($slCandidates)
                ? max($slCandidates[0] - $atrBuffer, $price - $maxDist)
                : $price - ($price * $slRatio);
        }

        // Pick TP1, TP2, TP3 — spaced at least $minStep apart
        $tp1 = null; $tp2 = null; $tp3 = null;
        $picked = [];
        foreach ($tpCandidates as $candidate) {
            $ok = true;
            if (abs($candidate - $price) < $minStep) { $ok = false; }
            foreach ($picked as $prev) {
                if (abs($candidate - $prev) < $minStep) { $ok = false; break; }
            }
            if (!$ok) continue;
            $picked[] = $candidate;
            if ($tp1 === null)      { $tp1 = $candidate; }
            elseif ($tp2 === null)  { $tp2 = $candidate; }
            elseif ($tp3 === null)  { $tp3 = $candidate; break; }
        }

        // If not enough distinct levels found, synthesize the missing ones
        if ($tp1 === null) {
            $tp1 = $action === 'SELL' ? $price - $minStep * 2 : $price + $minStep * 2;
        }
        if ($tp2 === null) {
            $tp2 = $action === 'SELL' ? $tp1 - $minStep * 2 : $tp1 + $minStep * 2;
        }
        if ($tp3 === null) {
            $tp3 = $action === 'SELL' ? $tp2 - $minStep * 2 : $tp2 + $minStep * 2;
        }

        // Clamp to max distance
        $floor   = $price - $maxDist;
        $ceiling = $price + $maxDist;
        if ($action === 'SELL') {
            $tp1 = max($tp1, $floor); $tp2 = max($tp2, $floor); $tp3 = max($tp3, $floor);
            // Ensure TP1 > TP2 > TP3 for SELL
            if ($tp2 >= $tp1) $tp2 = $tp1 - $minStep;
            if ($tp3 >= $tp2) $tp3 = $tp2 - $minStep;
        } else {
            $tp1 = min($tp1, $ceiling); $tp2 = min($tp2, $ceiling); $tp3 = min($tp3, $ceiling);
            // Ensure TP1 < TP2 < TP3 for BUY
            if ($tp2 <= $tp1) $tp2 = $tp1 + $minStep;
            if ($tp3 <= $tp2) $tp3 = $tp2 + $minStep;
        }

        // Calculate final R:R using TP2
        $risk     = abs($price - $sl);
        $reward   = abs($tp2  - $price);
        $rrValue  = $risk > 0 ? round($reward / $risk, 2) : 0;

        if ($risk <= 0 || $rrValue <= 0) return null;

        Log::info('Deterministic TP/SL fallback computed', [
            'action' => $action, 'price' => $price,
            'tp1' => $tp1, 'tp2' => $tp2, 'tp3' => $tp3, 'sl' => $sl, 'rr' => $rrValue,
        ]);

        return [
            'tp1'         => round($tp1, 8),
            'tp2'         => round($tp2, 8),
            'tp3'         => round($tp3, 8),
            'sl'          => round($sl, 8),
            'risk_reward' => "1:{$rrValue}",
            'rr_value'    => $rrValue,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ⚡ DETERMINISM FIX — Layer 3: Signal Consistency Check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compare the current signal with the last one for the same symbol/timeframe.
     * If the action changed within the last 5 minutes (from e.g. SELL to WAIT)
     * AND the price difference is less than 0.1%,
     * it means the AI produced conflicting answers for identical market data.
     * We inject a visible warning so the user knows the signal is unstable.
     *
     * Returns a warning string, or null if signals are stable.
     */
    private function checkConsistency(string $symbol, string $timeframe, float $currentPrice, array $currentResult): ?string
    {
        $cacheKey  = "last_signal_{$symbol}_{$timeframe}";
        $lastSignal = \Illuminate\Support\Facades\Cache::get($cacheKey);

        // Store current result for the next call
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'action'     => $currentResult['action'] ?? 'WAIT',
            'risk_reward'=> $currentResult['risk_reward'] ?? 'N/A',
            'price'      => $currentPrice,
            'timestamp'  => now()->timestamp,
        ], 300); // remember for 5 minutes

        if (!$lastSignal || $currentPrice <= 0) return null;

        $secondsAgo  = now()->timestamp - (int) ($lastSignal['timestamp'] ?? 0);
        $lastAction  = $lastSignal['action'] ?? 'WAIT';
        $lastPrice   = (float) ($lastSignal['price'] ?? 0);
        $thisAction  = $currentResult['action'] ?? 'WAIT';

        // Calculate absolute price difference percentage
        $priceDiffPct = $lastPrice > 0 ? abs($currentPrice - $lastPrice) / $lastPrice : 0;

        // Only flag if actions differ AND they are within 300 seconds (5 mins) 
        // AND price difference is less than 0.1% (market barely moved)
        if ($secondsAgo <= 300 && $lastAction !== $thisAction && $priceDiffPct < 0.001) {
            $diffStr = number_format($priceDiffPct * 100, 3) . '%';
            $warning = "⚠️ SIGNAL INSTABILITY DETECTED: Previous signal ({$secondsAgo}s ago) was '{$lastAction}' "
                     . "but current signal is '{$thisAction}'. Price moved only {$diffStr}. "
                     . "This indicates AI non-determinism. Wait for confirmation before trading.";

            Log::warning('Signal instability detected', [
                'symbol'      => $symbol,
                'timeframe'   => $timeframe,
                'last_action' => $lastAction,
                'this_action' => $thisAction,
                'seconds_ago' => $secondsAgo,
            ]);

            return $warning;
        }

        return null;
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
            // 15m/30m/1h tuned from a real expectancy backtest (services/trade_optimizer.py,
            // then re-verified per-timeframe with services/optimize-trade-params) over
            // 1180-1380 points / 5 symbols each: tp_step ×0.5, sl_ratio ×1.5 won on every
            // timeframe tested. Opportunity-adjusted score improved 15m 0.026→0.309,
            // 30m 0.099→0.362, 1h 0.085→0.485, 4h 0.147→1.045. '1d'/'1w' left at the old
            // guessed values — not tested, don't extrapolate an untested timeframe.
            '15m'        => ['max_tp3' => 0.02,   'tp_step' => 0.005,  'sl_ratio' => 0.0225],
            '30m'        => ['max_tp3' => 0.02,   'tp_step' => 0.005,  'sl_ratio' => 0.0225],
            '1h'         => ['max_tp3' => 0.04,   'tp_step' => 0.01,   'sl_ratio' => 0.0375],
            // 4h: best combo used expiry×3 (not ×2 like the others) — tp_step/sl_ratio
            // multipliers are the same, only its expiry window differs below.
            '4h'         => ['max_tp3' => 0.075,  'tp_step' => 0.02,   'sl_ratio' => 0.075],
            '1d'         => ['max_tp3' => 0.25,   'tp_step' => 0.08,   'sl_ratio' => 0.08],
            '1w'         => ['max_tp3' => 0.50,   'tp_step' => 0.15,   'sl_ratio' => 0.15],
            default      => ['max_tp3' => 0.15,   'tp_step' => 0.04,   'sl_ratio' => 0.05],
        };
    }

    private function getExpiryHours(string $timeframe): int
    {
        return match ($timeframe) {
            '1m', '5m'         => 1,
            // Doubled (15m/30m/1h) or tripled (4h) per timeframe — same backtest as
            // getTimeframeConstraints() above; most trades were expiring unresolved
            // before a TP/SL was ever reached.
            '15m'              => 8,
            '30m'              => 8,
            '1h'               => 16,
            '4h'               => 72,
            '1d'               => 72,
            '1w'               => 168,
            default            => 24,
        };
    }
}
