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
use App\Services\TradeQualityService;
use App\Services\ScalpingMtfGate;
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
        private TradeQualityService   $tradeQualityService,
        private ScalpingMtfGate       $scalpingMtfGate,
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

            // MTF is active only when the Settings toggle is on AND all 3 timeframes
            // are set and different from each other. Previously the toggle was
            // validated/saved but never read — MTF fetch/prompt/report ran
            // unconditionally off the (always-distinct-by-default) timeframe pickers.
            $mtfToggleOn = (bool) ($settings['multi_timeframe'] ?? false);
            $mtfActive = $mtfToggleOn && $htfTf && $mtfTf && $ltfTf
                && $htfTf !== $mtfTf && $mtfTf !== $ltfTf && $htfTf !== $ltfTf;

            if ($mtfActive) {
                // Strict enforce MTF structure: ignore the single clicked timeframe from the UI route
                // and use the configured LTF as the base entry timeframe.
                $timeframe = $ltfTf;
            }

            $exchange = $coin->exchange ?? 'binance';

            // ── Optional analysis modules — see Settings > Analysis Modules ────
            $includeHarmonic         = (bool) ($settings['enable_harmonic_patterns'] ?? true);
            $includeClassical        = (bool) ($settings['enable_classical_patterns'] ?? true);
            $includeCandles          = (bool) ($settings['enable_candle_patterns'] ?? true);
            $includeWhaleData        = (bool) ($settings['enable_whale_tracking'] ?? true);
            $includeNewsSentiment    = (bool) ($settings['enable_news_sentiment'] ?? true);
            $includeMarketCycles     = (bool) ($settings['enable_market_cycles'] ?? true);
            // Execution-quality engines (always fetched on LTF only, never on HTF/MTF)
            $includeOrderBook        = (bool) ($settings['order_book_enabled'] ?? false);
            $includeCvd              = (bool) ($settings['cvd_enabled'] ?? false);
            $includeTakerPressure    = (bool) ($settings['taker_pressure_enabled'] ?? false);

            if ($isAutoScan) event(new MarketScanProgress("Fetching exchange data for {$symbol}...", 'info', $symbol));

            // ── Step 1: Technical Analysis ────────────────────────────────────
            // Always analyze the requested timeframe (used as LTF/entry timeframe)
            $taData = $this->taService->analyze($symbol, $exchange, $timeframe, 500, $includeHarmonic, $includeClassical, $includeCandles, $includeOrderBook, $includeCvd, $includeTakerPressure);

            // If MTF active, fetch HTF and MTF data as well (in parallel conceptually)
            $htfData = null;
            $mtfData = null;
            if ($mtfActive) {
                if ($isAutoScan) event(new MarketScanProgress("[MTF] Fetching {$htfTf} (Bias) + {$mtfTf} (Structure) data...", 'info', $symbol));
                $htfData = $this->taService->analyze($symbol, $exchange, $htfTf, 500, $includeHarmonic, $includeClassical, $includeCandles);
                $mtfData = $this->taService->analyze($symbol, $exchange, $mtfTf, 500, $includeHarmonic, $includeClassical, $includeCandles);
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

            // ── Step 3: News + Whale data (each independently toggleable in Settings) ──
            $news           = [];
            $sentimentScore = 0.0;
            $specificNews   = [];
            if ($includeNewsSentiment) {
                if ($isAutoScan) event(new MarketScanProgress("Checking global news & market sentiment...", 'info', $symbol));
                $news           = $this->newsService->getLatestNews('news', 10);
                $sentimentScore = $this->calculateSentimentScore($news, $symbol);

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
            }

            $relevantWhales = [];
            if ($includeWhaleData) {
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
            }

            // ── Step 3.5/3.55: Session Context + AMD Cycle ("Market Cycles" toggle) ──
            $sessionContext = null;
            $amdAnalysis    = null;
            if ($includeMarketCycles || (bool) ($settings['sessions_filter_enabled'] ?? false)) {
                $sessionContext = $this->buildSessionContext();
                $taData['session_context'] = $sessionContext;

                if ($isAutoScan && $sessionContext['is_noisy']) {
                    event(new MarketScanProgress(
                        "⏰ Session Warning: " . $sessionContext['session_alert'] . " — AI is aware.",
                        'warning',
                        $symbol
                    ));
                }

                if ($includeMarketCycles) {
                    // Purely additive — a coin with no valid accumulation zone or no manipulation
                    // detected simply gets status 'no_data'/'no_manipulation' and everything else
                    // (prompt, filters, report) proceeds exactly as it would without this layer.
                    $atrForAmd   = (float) ($taData['classical']['atr']['current'] ?? 0);
                    $amdAnalysis = $this->amdCycleService->analyze($symbol, $exchange, $atrForAmd, $settings);
                    $taData['amd_analysis'] = $amdAnalysis;

                    if ($isAutoScan && $amdAnalysis['manipulation_detected']) {
                        event(new MarketScanProgress(
                            "🎯 AMD Cycle: " . $amdAnalysis['summary'],
                            'warning',
                            $symbol
                        ));
                    }
                }
            }

            // ── Step 3.6: Pre-Trade Filters ───────────────────────────────────
            if ($isAutoScan) event(new MarketScanProgress("Running pre-trade quality filters...", 'info', $symbol));

            $filterResult = $this->preTradeFilter->run($taData, $settings, $timeframe);

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
            $skipAiOnWait   = (bool) ($settings['skip_ai_on_filter_wait'] ?? false);
            $aiDecisionMode = $settings['ai_decision_mode'] ?? 'ai';
            $aiProvider     = $settings['ai_provider'] ?? '';

            if ($aiDecisionMode === 'deterministic') {
                // Bypass the AI entirely — build the recommendation straight from the
                // deterministic Classical+SMC+Harmonic+Volume bias (bias_combiner.py),
                // gated by PreTradeFilterService, using the same tp_step/sl_ratio
                // constraints tuned in trade_optimizer.py. A 36-sample real-data
                // backtest found this performs comparably to layering the AI on top
                // (77.8% vs 75% win rate) at zero AI cost — see conversation notes.
                if ($isAutoScan) {
                    event(new MarketScanProgress(
                        "AI bypassed (ai_decision_mode=deterministic) — using Python bias directly.",
                        'info',
                        $symbol
                    ));
                }

                $adjustedConfidence = (int) ($filterResult['adjusted_confidence'] ?? ($taData['overall_confidence'] ?? 0));
                // Direction comes from the 5m EXECUTION timeframe (taData) — this is
                // the current "5m leads, 1h/15m are context only" architecture
                // (ScalpingMtfGate). An earlier round briefly made 15m (mtfData) the
                // direction source under an older "15m is primary" philosophy that
                // has since been explicitly reversed — that made this branch
                // structurally unable to ever pass ScalpingMtfGate's hard "action
                // must match 5m bias" consistency check whenever 15m and 5m
                // disagreed (observed live: PENGUUSDT bought on 15m=bullish 72%
                // while 5m was bearish, immediately hard-blocked). Single-TF mode
                // was never affected either way (mtfData is null there).
                $overallBias = strtoupper($taData['overall_bias'] ?? 'NEUTRAL');

                // Single gate: PreTradeFilterService::run() already computed the fully
                // regime/ATR-adjusted threshold and decided PROCEED vs WAIT from it
                // (filterResult['dynamic_threshold'] / pre_trade_verdict). Previously
                // this branch re-checked adjustedConfidence against a SEPARATE, flat
                // `pre_trade_min_confidence` (default 65) — capable of disagreeing with
                // the verdict computed two steps earlier. Trust that one verdict only.
                $action = 'WAIT';
                if (($filterResult['pre_trade_verdict'] ?? 'PROCEED') === 'PROCEED'
                    && in_array($overallBias, ['BULLISH', 'BEARISH'])) {
                    $action = $overallBias === 'BULLISH' ? 'BUY' : 'SELL';
                }

                $currentPriceDet = (float) ($taData['current_price'] ?? 0);
                $atrValueDet     = (float) ($taData['classical']['atr']['current'] ?? 0);
                $atrPctDet       = ($atrValueDet > 0 && $currentPriceDet > 0) ? ($atrValueDet / $currentPriceDet) * 100 : 0;
                $detConstraints  = $this->getTimeframeConstraints($timeframe, $atrPctDet);
                $isBuyDet        = $action === 'BUY';

                // TP/SL sourced from REAL chart structure (S/R, Order Blocks, FVGs,
                // Volume Profile) via computeDeterministicLevels() — the same function
                // the AI path falls back to for a low R:R. Only fall back to a plain
                // tp_step/sl_ratio % distance if no usable chart levels were found
                // (e.g. brand new coin with no S/R history yet).
                $tp1 = $tp2 = $tp3 = $sl = null;
                if ($action !== 'WAIT') {
                    $levels = $this->computeDeterministicLevels($action, $currentPriceDet, $taData, $detConstraints, 2.0, $timeframe);
                    if ($levels !== null) {
                        $tp1 = $levels['tp1']; $tp2 = $levels['tp2']; $tp3 = $levels['tp3']; $sl = $levels['sl'];
                    } else {
                        $tpStepDist = $currentPriceDet * $detConstraints['tp_step'];
                        $tp1 = $isBuyDet ? $currentPriceDet + $tpStepDist     : $currentPriceDet - $tpStepDist;
                        $tp2 = $isBuyDet ? $currentPriceDet + $tpStepDist * 2 : $currentPriceDet - $tpStepDist * 2;
                        $tp3 = $isBuyDet ? $currentPriceDet + $tpStepDist * 3 : $currentPriceDet - $tpStepDist * 3;
                    }
                }

                $aiResult = [
                    'action'      => $action,
                    'entry_price' => $currentPriceDet,
                    'tp1'         => $tp1,
                    'tp2'         => $tp2,
                    'tp3'         => $tp3,
                    'sl'          => $sl,
                    'risk_reward' => 'N/A',
                    'confidence'  => $adjustedConfidence,
                    'signal_confidence' => $adjustedConfidence, // Pre-Check quality score
                    'reasoning'   => "[Deterministic Engine — AI LLM not used. Bias: {$overallBias} ({$adjustedConfidence}%)" .
                                     (($filterResult['pre_trade_reason'] ?? null) ? " — {$filterResult['pre_trade_reason']}" : '') .
                                     '. TP/SL from chart structure (S/R/OB/FVG/Volume Profile).]',
                    'confluences' => $taData['confluences'] ?? [],
                    'invalidation' => null,
                    'decision_pipeline' => [
                        ['stage' => 'Signal',      'status' => 'pass', 'note' => "{$overallBias} bias"],
                        ['stage' => 'Pre-Check',   'status' => $filterResult['pre_trade_verdict'] === 'PROCEED' ? 'pass' : 'fail',
                                                   'note'  => "{$adjustedConfidence}% — " . ($filterResult['pre_trade_reason'] ?? 'OK')],
                        ['stage' => 'Deterministic Engine', 'status' => 'pass', 'note' => 'AI LLM bypassed per settings'],
                        ['stage' => 'Decision',    'status' => $action !== 'WAIT' ? 'pass' : 'wait', 'note' => $action],
                    ],
                ];
                $aiResult = $this->validateAndClampRecommendation($aiResult, $taData, $timeframe);

                // ── Phase 3: Trade Quality (R:R + EV + Grade) ────────────────────────
                $tqResult = $this->computeAndApplyTradeQuality($aiResult, $taData);
                $aiResult['confidence']    = $tqResult['adjusted_confidence'];
                $taData['trade_quality']   = $tqResult['trade_quality_data'];
                $analysis->update(['raw_data' => $taData]);
            } elseif ($skipAiOnWait && ($filterResult['pre_trade_verdict'] ?? 'PROCEED') === 'WAIT') {
                if ($isAutoScan) {
                    event(new MarketScanProgress(
                        "Pre-Trade Filter → WAIT. Skipping AI call to save tokens (skip_ai_on_filter_wait is on).",
                        'warning',
                        $symbol
                    ));
                }

                $filterConf = $filterResult['adjusted_confidence'] ?? 0;
                $aiResult = [
                    'action'       => 'WAIT',
                    'entry_price'  => $taData['current_price'] ?? null,
                    'tp1'          => null,
                    'tp2'          => null,
                    'tp3'          => null,
                    'sl'           => null,
                    'risk_reward'  => 'N/A',
                    'confidence'   => $filterConf,
                    'signal_confidence' => $filterConf,
                    'reasoning'    => '[Pre-Trade Filter: ' . ($filterResult['pre_trade_reason'] ?? 'Quality gate not met.') . '] (AI call skipped to save tokens)',
                    'confluences'  => $taData['confluences'] ?? [],
                    'invalidation' => null,
                    'sl_auto_widened' => false,
                    'decision_pipeline' => [
                        ['stage' => 'Signal',    'status' => 'pass', 'note' => 'Bias detected'],
                        ['stage' => 'Pre-Check', 'status' => 'fail', 'note' => "{$filterConf}% — " . ($filterResult['pre_trade_reason'] ?? 'Quality gate not met')],
                        ['stage' => 'Decision',  'status' => 'wait', 'note' => 'WAIT — Pre-Check gate rejected'],
                    ],
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

                // ── Phase 3: Trade Quality (R:R + EV + Grade) ────────────────────────
                // Previously this only ran in ai_decision_mode=deterministic — the
                // standard AI path (the default for most users) never computed R:R/EV
                // grading or its confidence adjustment at all, so "Trade Quality: Below
                // Target" style warnings and their -15..+5 confidence delta silently
                // never applied to AI-driven recommendations. Now it runs on every path.
                $tqResult = $this->computeAndApplyTradeQuality($aiResult, $taData);
                $aiResult['confidence']  = $tqResult['adjusted_confidence'];
                $taData['trade_quality'] = $tqResult['trade_quality_data'];
                $analysis->update(['raw_data' => $taData]);
            }

            // ── Pure-Scalping MTF Gate ─────────────────────────────────────────────
            // Enforces 1h=context / 15m=primary-direction / 5m=execution roles as a
            // HARD gate (not advisory) whenever MTF mode is active — see
            // ScalpingMtfGate docblock. No-ops entirely in single-TF mode. Runs
            // BEFORE the final confidence gate so its multiplier (never just a
            // penalty note) is baked into the number that gate actually checks.
            $aiResult = $this->applyScalpingMtfGate($aiResult, $taData);

            // ── Final Confidence Gate ─────────────────────────────────────────────
            // Single, authoritative re-check of the FINAL confidence (after every
            // downstream adjustment above) against the same threshold that approved
            // the trade — covers the deterministic, standard-AI, and skip-ai-on-wait
            // paths in one place. See applyFinalConfidenceGate() docblock.
            $aiResult = $this->applyFinalConfidenceGate($aiResult, $filterResult, $taData);

            // ── Hard Entry Conditions ─────────────────────────────────────────────
            // Binary structural gate — see applyHardEntryConditions() docblock. Runs
            // after the confidence gate so a trade already downgraded to WAIT is a
            // harmless no-op here.
            $aiResult = $this->applyHardEntryConditions($aiResult, $taData, $filterResult);

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

            // Append ATR/SL gate metadata + Trade Quality to the recommendation for the frontend
            // (not stored in DB — returned in-memory for this response only)
            $recommendationData = $recommendation->toArray();
            $recommendationData['sl_auto_widened']   = $aiResult['sl_auto_widened'] ?? false;
            $recommendationData['sl_original']       = $aiResult['sl_original']     ?? null;
            $recommendationData['sl_min_atr_dist']   = $aiResult['sl_min_atr_dist'] ?? null;
            $recommendationData['sl_atr_multiple']   = $aiResult['sl_atr_multiple'] ?? null;
            $recommendationData['tp_basis']          = $aiResult['tp_basis']        ?? null;
            // Previously only the Antigravity bridge response included these two —
            // the standard synchronous path (the default for most users) silently
            // dropped them, which is why a report field bound to "signal_confidence"
            // (the AI's original, pre-adjustment confidence) would show nothing.
            $recommendationData['signal_confidence'] = $aiResult['signal_confidence'] ?? $aiResult['confidence'] ?? 0;
            $recommendationData['decision_pipeline'] = $aiResult['decision_pipeline'] ?? null;

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

                // ── Phase 3: Trade Quality + Final Confidence Gate ────────────────
                // Same treatment as the synchronous path: R:R/EV grading must run
                // here too (previously skipped entirely for the Antigravity bridge),
                // and the final confidence must be re-checked against the threshold
                // after Trade Quality's adjustment before the action is trusted.
                $tqResult = $this->computeAndApplyTradeQuality($aiResult, $taData);
                $aiResult['confidence']  = $tqResult['adjusted_confidence'];
                $taData['trade_quality'] = $tqResult['trade_quality_data'];
                $aiResult = $this->applyScalpingMtfGate($aiResult, $taData);
                $aiResult = $this->applyFinalConfidenceGate($aiResult, $cachedFilterResult ?? [], $taData);
                $aiResult = $this->applyHardEntryConditions($aiResult, $taData, $cachedFilterResult ?? []);

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
                $bridgeRecData['sl_auto_widened']    = $aiResult['sl_auto_widened']    ?? false;
                $bridgeRecData['sl_original']        = $aiResult['sl_original']        ?? null;
                $bridgeRecData['sl_min_atr_dist']    = $aiResult['sl_min_atr_dist']    ?? null;
                $bridgeRecData['signal_confidence']  = $aiResult['signal_confidence']  ?? $aiResult['confidence'] ?? 0;
                $bridgeRecData['decision_pipeline']  = $aiResult['decision_pipeline']  ?? null;

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
        $minRiskReward  = (float) ($settings['min_risk_reward'] ?? 2.0);

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

        $entry = (float) ($aiResult['entry_price'] ?? $currentPrice);
        $tp1 = isset($aiResult['tp1']) ? (float) $aiResult['tp1'] : null;
        $tp2 = isset($aiResult['tp2']) ? (float) $aiResult['tp2'] : null;
        $tp3 = isset($aiResult['tp3']) ? (float) $aiResult['tp3'] : null;
        $sl  = isset($aiResult['sl'])  ? (float) $aiResult['sl']  : null;
        $aiResult['sl_auto_widened'] = false;

        // ── Step 0: ATR + Volatility Regime ──────────────────────────────────
        $atrValue = (float) ($taData['classical']['atr']['current'] ?? 0);
        $atrPct   = ($atrValue > 0 && $currentPrice > 0) ? ($atrValue / $currentPrice) * 100 : 0;
        $regime   = $this->getVolatilityRegime($atrPct, $timeframe);
        $mults    = $this->getAtrMultipliers($regime, $timeframe);
        $slMult   = $mults['sl'];
        // Numeric/percentile-based regime description (e.g. "🟢 Low (ATR=0.138% < 0.5%)")
        // instead of a bare regime name with no context for why it was classified that way.
        $regimeDesc = \App\Support\VolatilityRegime::describe($regime, $atrPct, $timeframe);

        // ATR-relative on 1m/5m — see getTimeframeConstraints() docblock. Computed
        // here (not before Step 0) specifically so it can use the real $atrPct.
        $constraints = $this->getTimeframeConstraints($timeframe, $atrPct);

        $maxDistance = $currentPrice * $constraints['max_tp3'];
        $minStep     = $currentPrice * $constraints['tp_step'] * 0.5;

        // ── Step 1: Entry Validation — adaptive ±0.5× ATR ────────────────────
        // More precise than fixed ±1%: adapts to each coin's volatility character.
        $maxEntryDrift = $atrValue > 0 ? $atrValue * 0.5 : $currentPrice * 0.01;
        if (abs($entry - $currentPrice) > $maxEntryDrift) {
            Log::warning('AI entry_price clamped (ATR-adaptive)', [
                'original'  => $entry, 'current' => $currentPrice,
                'max_drift' => $maxEntryDrift, 'regime'  => $regime,
            ]);
            $entry = $currentPrice;
        }

        // ── Step 2: SL — Structure-first, ATR-regime fallback ────────────────
        // Priority order: (a) AI SL if valid & meets regime min, (b) ATR×regime fallback.
        // The multiplier scales with volatility so high-ATR coins get proportionally wider stops.
        //
        // $slBasisNote tracks a SINGLE description of how the final SL was derived.
        // It is intentionally NOT appended to reasoning here — the R:R gate below can
        // still overwrite $sl via the deterministic structure fallback, which used to
        // leave this note stale (claiming "ATR×1.5" reasoning next to a SL that was
        // actually structure-derived and several ATRs away). The note — plus the
        // *realized* ATR multiple actually used — is appended once, at the end of this
        // function, after $sl has its final value.
        $slBasisNote = null;
        $minSlDist = $atrValue > 0
            ? $atrValue * $slMult
            : $currentPrice * $constraints['sl_ratio'];

        if ($action === 'BUY') {
            if ($sl === null || $sl >= $entry) {
                $sl = round($entry - $minSlDist, 8);
                $slBasisNote = "SL derived from ATR×{$slMult}, {$regimeDesc}: \${$sl}";
            } elseif (abs($entry - $sl) < $minSlDist) {
                $originalSl = $sl;
                $sl = round($entry - $minSlDist, 8);
                $aiResult['sl_auto_widened'] = true;
                $aiResult['sl_original']     = round($originalSl, 8);
                $aiResult['sl_min_atr_dist'] = round($minSlDist, 8);
                $slBasisNote = "⚠️ SL widened: \${$originalSl} → \${$sl} ({$regimeDesc}, need ATR×{$slMult}={$minSlDist})";
            }
            $sl = max($sl, $entry - $maxDistance);

        } elseif ($action === 'SELL') {
            if ($sl === null || $sl <= $entry) {
                $sl = round($entry + $minSlDist, 8);
                $slBasisNote = "SL derived from ATR×{$slMult}, {$regimeDesc}: \${$sl}";
            } elseif (abs($entry - $sl) < $minSlDist) {
                $originalSl = $sl;
                $sl = round($entry + $minSlDist, 8);
                $aiResult['sl_auto_widened'] = true;
                $aiResult['sl_original']     = round($originalSl, 8);
                $aiResult['sl_min_atr_dist'] = round($minSlDist, 8);
                $slBasisNote = "⚠️ SL widened: \${$originalSl} → \${$sl} ({$regimeDesc}, need ATR×{$slMult}={$minSlDist})";
            }
            $sl = min($sl, $entry + $maxDistance);
        }

        // ── Step 2.5: Max SL/ATR distance cap ─────────────────────────────────
        // The minimum check above only ever WIDENS a too-tight SL — nothing capped
        // an SL that was already far (an AI-provided SL, or one this function
        // hasn't touched because it already exceeded $minSlDist). A distant support/
        // OB level, or a generous AI guess, can otherwise produce an effectively
        // unbounded stop (observed: 4.83x–6.27x ATR on real reports next to a note
        // that just said "SL accepted"/"VERY WIDE" — the minimum was never meant to
        // imply any wider SL is automatically fine). Two-tier response, matching
        // "hard cap, and >3x ATR is a REJECT, not a bigger number to clamp to":
        //   - Moderately over the regime cap  → clamp inward to the cap.
        //   - Grossly over (>1.6x the cap)    → no coherent nearby stop exists;
        //     reject the trade outright instead of fabricating an arbitrary SL.
        if ($atrValue > 0) {
            $maxSlDist  = $atrValue * \App\Support\VolatilityRegime::maxSlMultiplier($regime, $timeframe);
            $actualDist = abs($entry - $sl);

            if ($actualDist > $maxSlDist * 1.6) {
                $actualMultiple = round($actualDist / $atrValue, 2);
                $capMultiple    = round($maxSlDist / $atrValue, 2);
                Log::info('SL rejected: grossly exceeds regime ATR cap', [
                    'symbol' => $taData['symbol'] ?? '?', 'actual_multiple' => $actualMultiple, 'cap_multiple' => $capMultiple,
                ]);
                $aiResult['action']      = 'WAIT';
                $aiResult['tp1']         = null;
                $aiResult['tp2']         = null;
                $aiResult['tp3']         = null;
                $aiResult['sl']          = null;
                $aiResult['risk_reward'] = 'N/A';
                $aiResult['confidence']  = 0;
                $aiResult['reasoning']   = trim(($aiResult['reasoning'] ?? '') .
                    " [🚫 SL Rejected: nearest available stop is {$actualMultiple}× ATR away — no coherent stop exists "
                    . "within the {$capMultiple}× ATR maximum for {$regimeDesc}. Downgraded to WAIT instead of using an "
                    . "arbitrary/fabricated SL.]");
                return $aiResult;
            }

            if ($actualDist > $maxSlDist) {
                $cappedSl = $action === 'BUY' ? round($entry - $maxSlDist, 8) : round($entry + $maxSlDist, 8);
                $slBasisNote = "⚠️ SL capped: structure/AI-derived SL (\${$sl}) exceeded the "
                    . round($maxSlDist / $atrValue, 2) . "× ATR maximum for {$regimeDesc} — pulled in to \${$cappedSl}.";
                $sl = $cappedSl;
            }
        }

        // ── Step 3: TP Validation — R:R-aware minimum distances ──────────────
        // Derive mandatory TP distances from SL distance + R:R targets.
        // This prevents the previous bug where TPs were fixed percentages independent of SL.
        $slDistance    = abs($entry - $sl);
        $minTP1Dist    = $slDistance * $minRiskReward;  // R:R ≥ 1:1.5
        $targetTP2Dist = $slDistance * 2.0;              // Target R:R = 1:2.0

        Log::info('Phase-2 TP engine: regime-aware distances', [
            'symbol'      => $taData['symbol'] ?? '?',
            'regime'      => $regime,
            'atr'         => $atrValue,
            'sl_mult'     => $slMult,
            'sl_distance' => $slDistance,
            'min_tp1'     => $minTP1Dist,
            'target_tp2'  => $targetTP2Dist,
        ]);

        // Track WHERE each TP actually came from (real chart structure vs a synthesized
        // ATR/R:R step) so the reasoning can say so plainly instead of TPs looking like
        // fixed percentages with no visible basis.
        $tp1Basis = 'ai'; $tp2Basis = 'ai'; $tp3Basis = 'ai';

        if ($action === 'BUY') {
            // TP1: must reach at least minRR × SL distance from entry
            if ($tp1 === null || $tp1 <= $entry || ($tp1 - $entry) < $minTP1Dist) {
                $fromStr = $this->findStructureTP('BUY', $entry, $minTP1Dist, $taData);
                $tp1 = $fromStr ?? round($entry + $minTP1Dist, 8);
                $tp1Basis = $fromStr !== null ? 'structure' : 'synthesized (R:R step)';
            }
            // TP2: targets R:R = 1:2.0 from entry
            $tp2MinNeeded = max($targetTP2Dist, ($tp1 - $entry) + $minStep);
            if ($tp2 === null || $tp2 <= $tp1 || ($tp2 - $entry) < $tp2MinNeeded) {
                $fromStr = $this->findStructureTP('BUY', $entry, $tp2MinNeeded, $taData);
                $tp2 = ($fromStr !== null && $fromStr > $tp1) ? $fromStr : round($tp1 + $minStep, 8);
                $tp2Basis = ($fromStr !== null && $fromStr > $tp1) ? 'structure' : 'synthesized (step past TP1)';
            }
            // TP3: above TP2 — always a fixed step past TP2, never structure-searched
            if ($tp3 === null || $tp3 <= $tp2) {
                $tp3 = round($tp2 + $minStep, 8);
                $tp3Basis = 'synthesized (step past TP2)';
            }
            // Clamp to max distance
            $ceiling = $entry + $maxDistance;
            $tp1 = min($tp1, $ceiling);
            $tp2 = min($tp2, $ceiling);
            $tp3 = min($tp3, $ceiling);
            if ($tp2 <= $tp1) $tp2 = $tp1 + $minStep;
            if ($tp3 <= $tp2) $tp3 = $tp2 + $minStep;

        } elseif ($action === 'SELL') {
            // TP1: must be at least minRR × SL distance below entry
            if ($tp1 === null || $tp1 >= $entry || ($entry - $tp1) < $minTP1Dist) {
                $fromStr = $this->findStructureTP('SELL', $entry, $minTP1Dist, $taData);
                $tp1 = $fromStr ?? round($entry - $minTP1Dist, 8);
                $tp1Basis = $fromStr !== null ? 'structure' : 'synthesized (R:R step)';
            }
            // TP2: targets R:R = 1:2.0
            $tp2MinNeeded = max($targetTP2Dist, ($entry - $tp1) + $minStep);
            if ($tp2 === null || $tp2 >= $tp1 || ($entry - $tp2) < $tp2MinNeeded) {
                $fromStr = $this->findStructureTP('SELL', $entry, $tp2MinNeeded, $taData);
                $tp2 = ($fromStr !== null && $fromStr < $tp1) ? $fromStr : round($tp1 - $minStep, 8);
                $tp2Basis = ($fromStr !== null && $fromStr < $tp1) ? 'structure' : 'synthesized (step past TP1)';
            }
            // TP3: below TP2 — always a fixed step past TP2, never structure-searched
            if ($tp3 === null || $tp3 >= $tp2) {
                $tp3 = round($tp2 - $minStep, 8);
                $tp3Basis = 'synthesized (step past TP2)';
            }
            // Clamp to max distance
            $floor = $entry - $maxDistance;
            $tp1 = max($tp1, $floor);
            $tp2 = max($tp2, $floor);
            $tp3 = max($tp3, $floor);
            if ($tp2 >= $tp1) $tp2 = $tp1 - $minStep;
            if ($tp3 >= $tp2) $tp3 = $tp2 - $minStep;
        }

        // ── Step 3.5: Blocking-FVG clamp ──────────────────────────────────────
        // A TP that requires price to punch through an unconfirmed, opposing-
        // direction Fair Value Gap is not a realistic target — the prompt already
        // tells the AI this ("BLOCKING BEARISH FVG... blocks upward move"), but
        // nothing enforced it against the actual TP values chosen (AI-provided,
        // structure-found, or synthesized). Pull TP1/TP2 back to just before the
        // nearest blocking zone instead of assuming the breakout already happened.
        if ($action === 'BUY') {
            $tp1 = $this->clampTpAgainstBlockingFvg('BUY', $entry, $tp1, $taData);
            $tp2 = $this->clampTpAgainstBlockingFvg('BUY', $entry, $tp2, $taData);
            if ($tp2 <= $tp1) $tp2 = $tp1 + $minStep;
            if ($tp3 <= $tp2) $tp3 = $tp2 + $minStep;
        } elseif ($action === 'SELL') {
            $tp1 = $this->clampTpAgainstBlockingFvg('SELL', $entry, $tp1, $taData);
            $tp2 = $this->clampTpAgainstBlockingFvg('SELL', $entry, $tp2, $taData);
            if ($tp2 >= $tp1) $tp2 = $tp1 - $minStep;
            if ($tp3 >= $tp2) $tp3 = $tp2 - $minStep;
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
            $preFallbackRr = $rrValue;
            $detLevels = $this->computeDeterministicLevels($action, $entry, $taData, $constraints, $minRiskReward, $timeframe);

            if ($detLevels !== null && $detLevels['rr_value'] >= $minRiskReward) {
                // Deterministic fallback succeeded — use its levels. This REPLACES
                // $slBasisNote (not appends) — the ATR-regime note above described a
                // SL that no longer exists; the true basis for the SL being returned
                // is now the structure engine (S/R / OB / FVG / Volume Profile) plus
                // its own ATR buffer, which is NOT a flat "1.5x ATR" distance.
                $tp1 = $detLevels['tp1'];
                $tp2 = $detLevels['tp2'];
                $tp3 = $detLevels['tp3'];
                $sl  = $detLevels['sl'];
                $rrValue    = $detLevels['rr_value'];
                $riskReward = $detLevels['risk_reward'];
                $tp1Basis = $tp2Basis = $tp3Basis = 'deterministic engine (structure-first, ATR fallback)';
                // Re-apply the blocking-FVG clamp — computeDeterministicLevels() can
                // pick an FVG midpoint as a TP candidate regardless of whether that FVG
                // opposes the trade direction.
                $tp1 = $this->clampTpAgainstBlockingFvg($action, $entry, $tp1, $taData);
                $tp2 = $this->clampTpAgainstBlockingFvg($action, $entry, $tp2, $taData);
                if ($action === 'BUY') {
                    if ($tp2 <= $tp1) $tp2 = $tp1 + $minStep;
                    if ($tp3 <= $tp2) $tp3 = $tp2 + $minStep;
                } else {
                    if ($tp2 >= $tp1) $tp2 = $tp1 - $minStep;
                    if ($tp3 >= $tp2) $tp3 = $tp2 - $minStep;
                }
                $slBasisNote = "SL/TP recalculated by deterministic structure engine (nearest S/R, Order Block, "
                    . "FVG, or Volume Profile level + ATR buffer) — AI's R:R (1:{$preFallbackRr}) was below "
                    . "the 1:{$minRiskReward} minimum, structure engine achieved 1:{$detLevels['rr_value']}. "
                    . "This SL is NOT a flat ATR multiple — it is anchored to the nearest invalidation structure.";
            } else {
                // Even deterministic fallback failed — now downgrade to WAIT
                $signalConf = $aiResult['signal_confidence'] ?? $aiResult['confidence'] ?? 0;
                $detRR = $detLevels['rr_value'] ?? 'N/A';
                Log::info('Recommendation downgraded to WAIT: R:R below minimum even after deterministic recalculation', [
                    'original_action' => $action,
                    'ai_rr'           => $rrValue,
                    'det_rr'          => $detRR,
                    'required'        => $minRiskReward,
                ]);
                $aiResult['action']             = 'WAIT';
                $aiResult['tp1']                = null;
                $aiResult['tp2']                = null;
                $aiResult['tp3']                = null;
                $aiResult['sl']                 = null;
                $aiResult['risk_reward']        = 'N/A';
                $aiResult['signal_confidence']  = $signalConf; // preserve pre-check quality
                $aiResult['reasoning']          = trim(($aiResult['reasoning'] ?? '') .
                    " [Risk Gate Rejected: R:R ratio ({$riskReward}) below minimum 1:{$minRiskReward} even after deterministic TP/SL recalculation (best achieved: 1:{$detRR}).]");
                $aiResult['confidence']         = 0;
                $aiResult['decision_pipeline']  = [
                    ['stage' => 'Signal',      'status' => 'pass', 'note' => "{$action} signal — quality {$signalConf}%"],
                    ['stage' => 'Pre-Check',   'status' => 'pass', 'note' => "{$signalConf}% — PROCEED"],
                    ['stage' => 'R:R Gate',    'status' => 'fail', 'note' => "Got {$riskReward}, need 1:{$minRiskReward}. Det. fallback: 1:{$detRR}"],
                    ['stage' => 'Decision',    'status' => 'wait', 'note' => 'WAIT — R:R below threshold'],
                ];
                return $aiResult;
            }
        }

        // ── Final SL/ATR truth note ───────────────────────────────────────────
        // Append exactly ONE accurate note describing how the SL that's actually
        // being returned was derived, plus the REALIZED ATR multiple (not a label
        // that may no longer match reality after the R:R fallback above possibly
        // replaced the SL with a structure-derived one).
        if ($atrValue > 0 && $sl !== null) {
            $realizedAtrMultiple = round(abs($entry - $sl) / $atrValue, 2);
            $aiResult['sl_atr_multiple'] = $realizedAtrMultiple;
            $note = $slBasisNote !== null
                ? "{$slBasisNote} — realized distance = {$realizedAtrMultiple}× ATR."
                : "SL accepted from AI (met the {$slMult}× ATR minimum, {$regimeDesc}) — realized distance = {$realizedAtrMultiple}× ATR.";
            $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') . " [{$note}]");
        }

        // TP basis note — makes visible whether each TP came from real chart structure
        // or a synthesized ATR/R:R step, instead of TP1/TP2/TP3 looking like fixed,
        // unexplained percentages of price.
        $aiResult['tp_basis'] = ['tp1' => $tp1Basis, 'tp2' => $tp2Basis, 'tp3' => $tp3Basis];
        $aiResult['reasoning'] = trim(($aiResult['reasoning'] ?? '') .
            " [TP basis: TP1={$tp1Basis}, TP2={$tp2Basis}, TP3={$tp3Basis}.]");

        // ── Liquidity sweep vs. FINAL action consistency check ────────────────
        // PreTradeFilterService already applies a bonus/penalty for sweep direction
        // vs. overall_bias (a pre-AI proxy), but overall_bias and the AI's actual
        // final action can diverge (the prompt explicitly allows counter-trend
        // trades in ranging markets). Re-check against the real action here so a
        // bearish sweep can never sit next to a confidence-boosted BUY (or vice
        // versa) in the final report.
        $aiResult = $this->applySweepActionConsistencyCheck($aiResult, $taData);

        // Preserve the AI's ORIGINAL self-reported confidence before it gets clamped
        // below — previously this was silently overwritten with zero trace anywhere
        // (only an ephemeral, conditional log line), which is why a report field like
        // "Original Conf." would show N/A even though Raw Score / Technical Confidence
        // were populated. `signal_confidence` already exists as a named concept
        // elsewhere in this codebase (deterministic mode, skip-ai-on-wait) for exactly
        // this "pre-adjustment" number — reuse the same field instead of leaving it
        // unset on the (most common) standard AI path.
        if (!isset($aiResult['signal_confidence'])) {
            $aiResult['signal_confidence'] = (int) round((float) ($aiResult['confidence'] ?? 0));
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

        // ── Step 6: Costs & Slippage Gate ──────────────────────────────────────
        if (($settings['costs_filter_enabled'] ?? false) && $action !== 'WAIT' && $tp1 !== null && $entry > 0) {
            $expectedEdgeBps = (abs($tp1 - $entry) / $entry) * 10000;
            $feeBps = (float)($settings['costs_taker_fee_bps'] ?? 5.0) * 2;
            $slippageBps = (float)($settings['costs_slippage_bps'] ?? 3.0);
            $totalCostBps = $feeBps + $slippageBps;
            $netEdgeBps = $expectedEdgeBps - $totalCostBps;
            $minNetEdgeBps = (float)($settings['costs_min_net_edge_bps'] ?? 5.0);

            if ($netEdgeBps < $minNetEdgeBps) {
                Log::info('Recommendation auto-downgraded to WAIT: net edge below cost/slippage threshold', [
                    'symbol' => $taData['symbol'] ?? '?',
                    'action' => $action,
                    'expected_edge_bps' => $expectedEdgeBps,
                    'total_cost_bps' => $totalCostBps,
                    'net_edge_bps' => $netEdgeBps,
                    'min_net_edge_bps' => $minNetEdgeBps,
                ]);
                $aiResult['action']             = 'WAIT';
                $aiResult['tp1']                = null;
                $aiResult['tp2']                = null;
                $aiResult['tp3']                = null;
                $aiResult['sl']                 = null;
                $aiResult['risk_reward']        = 'N/A';
                $aiResult['confidence']         = 0;
                $aiResult['reasoning']          = trim(($aiResult['reasoning'] ?? '') .
                    " [🚫 Cost Gate: Expected profit of TP1 (" . round($expectedEdgeBps / 100, 2) . "% / " . round($expectedEdgeBps, 1) . " bps) minus fees & slippage (" . round($totalCostBps, 1) . " bps) leaves a net edge of " . round($netEdgeBps, 1) . " bps, which is below the minimum required " . round($minNetEdgeBps, 1) . " bps.]");
                $aiResult['decision_pipeline']  = [
                    ['stage' => 'Signal',      'status' => 'pass', 'note' => "{$action} signal"],
                    ['stage' => 'Pre-Check',   'status' => 'pass', 'note' => 'PROCEED'],
                    ['stage' => 'Cost Gate',   'status' => 'fail', 'note' => "Net " . round($netEdgeBps, 1) . " bps vs " . round($minNetEdgeBps, 1) . " bps min"],
                    ['stage' => 'Decision',    'status' => 'wait', 'note' => 'WAIT — Net edge below cost threshold'],
                ];
            }
        }

        return $aiResult;
    }

    /**
     * The pre-trade verdict (PROCEED/WAIT) is decided ONCE, early, from the
     * pre-AI adjusted_confidence. Everything downstream of that — SL/TP
     * validation, the sweep/action consistency check, AI-confidence
     * reconciliation, and Trade Quality's R:R/EV adjustment (-15 to +5) — can
     * still erode the final confidence well below the threshold that approved
     * the trade in the first place, yet nothing re-checked the FINAL number
     * against that threshold before now: the action stayed BUY/SELL even
     * after confidence had drifted under the gate that was supposed to
     * authorize it (e.g. a trade approved at 65% ending up reported as a BUY
     * at 48% final confidence). This is the single, final, authoritative gate
     * — applied once, after every confidence adjustment has already happened,
     * on every decision path (deterministic, standard AI, and bridge).
     */
    private function applyFinalConfidenceGate(array $aiResult, array $filterResult, array $taData): array
    {
        $action = $aiResult['action'] ?? 'WAIT';
        if (!in_array($action, ['BUY', 'SELL'])) {
            return $aiResult;
        }

        $finalConfidence = (int) ($aiResult['confidence'] ?? 0);
        $threshold       = (int) ($filterResult['dynamic_threshold'] ?? 65);

        if ($finalConfidence < $threshold) {
            Log::info('Final confidence gate: downgrading to WAIT', [
                'symbol'           => $taData['symbol'] ?? '?',
                'original_action'  => $action,
                'final_confidence' => $finalConfidence,
                'threshold'        => $threshold,
            ]);
            $aiResult['action']      = 'WAIT';
            $aiResult['tp1']         = null;
            $aiResult['tp2']         = null;
            $aiResult['tp3']         = null;
            $aiResult['sl']          = null;
            $aiResult['risk_reward'] = 'N/A';
            $aiResult['reasoning']   = trim(($aiResult['reasoning'] ?? '') .
                " [🚫 Final Confidence Gate: after all downstream adjustments (SL/TP validation, "
                . "sweep consistency, Trade Quality R:R/EV), confidence is {$finalConfidence}% — below "
                . "the {$threshold}% threshold that originally approved this trade. Downgraded to WAIT "
                . "so the reported action and confidence never contradict each other.]");
        }

        return $aiResult;
    }

    /**
     * Thin wrapper around ScalpingMtfGate::evaluate() — no-ops entirely outside
     * MTF mode (single-TF requests never have htf_data/mtf_data). 5m owns the
     * execution decision; 1h/15m are context only (see ScalpingMtfGate
     * docblock). On WAIT, downgrades exactly like the other hard gates, using
     * the gate's own next_trigger_needed text. On PROCEED, REPLACES confidence
     * outright with the gate's from-scratch Final Confidence (Execution 40% +
     * Setup 30% + Direction 20% + Risk 10%) — not a multiplier on the old
     * number — and attaches the full §33 scalping report.
     */
    private function applyScalpingMtfGate(array $aiResult, array $taData): array
    {
        $action = $aiResult['action'] ?? 'WAIT';
        if (!in_array($action, ['BUY', 'SELL'])) {
            return $aiResult;
        }
        if (!isset($taData['htf_data']) || !isset($taData['mtf_data'])) {
            return $aiResult; // single-TF mode — unaffected
        }

        $rrValue = 0.0;
        if (preg_match('/1:(\d+(?:\.\d+)?)/', (string) ($aiResult['risk_reward'] ?? ''), $m)) {
            $rrValue = (float) $m[1];
        }

        $evaluation = $this->scalpingMtfGate->evaluate($action, $taData, $rrValue);
        $aiResult['scalping_report'] = $evaluation['report'];

        if ($evaluation['verdict'] === 'WAIT') {
            Log::info('Scalping MTF gate: downgrading to WAIT', [
                'symbol' => $taData['symbol'] ?? '?', 'original_action' => $action, 'reason' => $evaluation['reason'],
            ]);
            $aiResult['action']      = 'WAIT';
            $aiResult['tp1']         = null;
            $aiResult['tp2']         = null;
            $aiResult['tp3']         = null;
            $aiResult['sl']          = null;
            $aiResult['risk_reward'] = 'N/A';
            $aiResult['confidence']  = $evaluation['final_confidence'];
            $aiResult['reasoning']   = trim(($aiResult['reasoning'] ?? '') .
                " [{$evaluation['report']['primary_reason']} — {$evaluation['reason']} Next trigger needed: "
                . "{$evaluation['report']['next_trigger_needed']}]");
            return $aiResult;
        }

        $report = $evaluation['report'];
        $aiResult['confidence'] = $evaluation['final_confidence'];
        $aiResult['scalp_grade'] = $report['grade'];
        $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
            " [Scalp Grade {$report['grade']} — Final Confidence {$report['execution_confidence']}% "
            . "(Execution {$report['execution_score']} / Setup {$report['setup_quality']} / "
            . "Direction {$report['direction_confidence']} / Risk {$report['risk_score']}). "
            . "MTF: {$report['mtf_direction']} ({$report['mtf_strength']}) — context only, did not block or "
            . "force this trade.]");

        return $aiResult;
    }

    /**
     * The core lesson from reviewing several real reports (DOT/USDT, GRAM, XAUT,
     * LINK): a high enough SCORE was being allowed to substitute for an actual
     * TRIGGER. DOT/USDT is the clearest case — Classical Bearish 70%, but SMC
     * Neutral 50%, a conflicting bullish Double Bottom pattern, Ranging regime,
     * no BOS, no liquidity sweep, no volume spike — and it still produced SELL.
     * No amount of point-scoring should be able to compensate for the absence of
     * a real trigger. This is a hard, binary gate: if ANY core condition fails,
     * the result is WAIT — never a reduced-confidence version of the same trade.
     *
     * Core conditions for BUY/SELL to survive:
     *   1. Classical bias must actually agree with the trade direction.
     *   2. SMC bias must not directly oppose the trade direction (neutral is
     *      tolerated, but then condition 4 below must supply the confirmation
     *      neutral SMC didn't).
     *   3. No still-active (non-invalidated), high-confidence chart pattern
     *      opposing the trade direction — an un-broken Double Bottom sitting
     *      right against a SELL is exactly this case.
     *   4. At least ONE real trigger: an aligned liquidity sweep, an aligned
     *      BOS/CHoCH, or confirmed SMC structural confluence (a proxy for
     *      "rejection off resistance/support/OB/FVG"). A directional lean from
     *      Classical alone, with nothing else, is not an entry signal — this
     *      condition alone also covers "ranging market with no confirmation",
     *      since none of the three trigger types will be present there either.
     */
    private function applyHardEntryConditions(array $aiResult, array $taData, array $filterResult): array
    {
        $action = $aiResult['action'] ?? 'WAIT';
        if (!in_array($action, ['BUY', 'SELL'])) {
            return $aiResult;
        }

        $actionDir   = $action === 'BUY' ? 'bullish' : 'bearish';
        $opposingDir = $action === 'BUY' ? 'bearish' : 'bullish';
        $failures    = [];

        // 1. Classical bias must agree with the trade direction
        $classicalBias = strtolower($taData['classical']['bias'] ?? 'neutral');
        if ($classicalBias !== $actionDir) {
            $failures[] = "Classical bias ({$classicalBias}) does not confirm {$action}";
        }

        // 2. SMC bias must not directly oppose
        $smcBias = strtolower($taData['smc']['bias'] ?? 'neutral');
        if ($smcBias === $opposingDir) {
            $failures[] = "SMC bias ({$smcBias}) directly opposes {$action}";
        }

        // 3. No still-active, high-confidence opposing chart pattern. Patterns
        // tagged "invalidated" by classical_analysis.py's _annotate_pattern_status()
        // have already been broken against their own thesis and don't count.
        foreach ($taData['classical']['chart_patterns'] ?? [] as $p) {
            if (strtolower($p['direction'] ?? '') !== $opposingDir) continue;
            if (($p['status'] ?? null) === 'invalidated') continue;
            if ((int) ($p['confidence'] ?? 0) >= 60) {
                $pName = $p['name'] ?? 'pattern';
                $pConf = $p['confidence'] ?? '?';
                $pStat = $p['status'] ?? 'unknown';
                $failures[] = "Unresolved opposing pattern: {$pName} ({$pConf}%, status={$pStat})";
                break;
            }
        }

        // 4. At least one real trigger
        $hasTrigger = false;
        $activeSweep = $taData['liquidity_sweeps']['active_sweep'] ?? null;
        if ($activeSweep && strtolower($activeSweep['direction'] ?? '') === $actionDir) {
            $hasTrigger = true;
        }
        if (!$hasTrigger) {
            $structure = $taData['smc']['market_structure'] ?? [];
            foreach (array_merge($structure['bos'] ?? [], $structure['choch'] ?? []) as $s) {
                if (strtolower($s['direction'] ?? '') === $actionDir) { $hasTrigger = true; break; }
            }
        }
        if (!$hasTrigger) {
            $smcConfluenceCount = $filterResult['filter_log']['smc_confluence']['count'] ?? 0;
            if ($smcConfluenceCount >= 1) {
                $hasTrigger = true;
            }
        }
        if (!$hasTrigger) {
            $failures[] = 'No trigger present (no aligned liquidity sweep, BOS/CHoCH, or SMC structural '
                        . 'confluence) — a directional lean alone is not an entry signal';
        }

        if (!empty($failures)) {
            Log::info('Hard entry conditions failed — downgrading to WAIT', [
                'symbol' => $taData['symbol'] ?? '?', 'action' => $action, 'failures' => $failures,
            ]);
            $aiResult['action']      = 'WAIT';
            $aiResult['tp1']         = null;
            $aiResult['tp2']         = null;
            $aiResult['tp3']         = null;
            $aiResult['sl']          = null;
            $aiResult['risk_reward'] = 'N/A';
            $aiResult['reasoning']   = trim(($aiResult['reasoning'] ?? '') .
                ' [🚫 Hard Entry Conditions failed — a score cannot compensate for a missing trigger: '
                . implode('; ', $failures) . '. Downgraded to WAIT.]');
        }

        return $aiResult;
    }

    /**
     * Compute Trade Quality metrics (Phase 3) after the recommendation has been
     * validated and R:R finalized. Returns an array with:
     *   - adjusted_confidence: the confidence after applying R:R and EV deltas
     *   - trade_quality_data:  the full trade_quality object for raw_data / report
     */
    private function computeAndApplyTradeQuality(array $aiResult, array $taData): array
    {
        $action = $aiResult['action'] ?? 'WAIT';
        $entry  = isset($aiResult['entry_price']) ? (float) $aiResult['entry_price'] : null;
        $tp2    = isset($aiResult['tp2'])          ? (float) $aiResult['tp2']        : null;
        $sl     = isset($aiResult['sl'])           ? (float) $aiResult['sl']         : null;

        // Parse finalized R:R value (stored as "1:2.5" string)
        $rrStr   = $aiResult['risk_reward'] ?? '1:0';
        $rrValue = 0.0;
        if (preg_match('/1:(\d+(?:\.\d+)?)/', $rrStr, $m)) {
            $rrValue = (float) $m[1];
        }

        $filterLog    = $taData['pre_trade_filter']['filter_log'] ?? [];
        if (isset($aiResult['scalping_report'])) {
            $scalpReport = $aiResult['scalping_report'];
            $filterLog['mtf_strength'] = [
                'status' => ($scalpReport['mtf_status'] === 'CONFLICT' || $scalpReport['mtf_strength'] === 'WEAK_ALIGNMENT')
                    ? 'weak_mtf_strength'
                    : 'mtf_strength_confirmed',
                'min_confidence' => min(
                    (int)($taData['htf_data']['overall_confidence'] ?? 0),
                    (int)($taData['mtf_data']['overall_confidence'] ?? 0),
                    (int)($taData['overall_confidence'] ?? 0)
                ),
            ];
        } elseif (isset($taData['htf_data']) && isset($taData['mtf_data'])) {
            $htfBias = strtolower($taData['htf_data']['overall_bias'] ?? 'neutral');
            $mtfBias = strtolower($taData['mtf_data']['overall_bias'] ?? 'neutral');
            $ltfBias = strtolower($taData['overall_bias'] ?? 'neutral');
            $actionDir = ($action === 'BUY') ? 'bullish' : (($action === 'SELL') ? 'bearish' : 'neutral');
            
            $aligned = ($actionDir !== 'neutral' && $htfBias === $actionDir && $mtfBias === $actionDir && $ltfBias === $actionDir);
            $filterLog['mtf_strength'] = [
                'status' => $aligned ? 'mtf_strength_confirmed' : 'weak_mtf_strength',
                'min_confidence' => min(
                    (int)($taData['htf_data']['overall_confidence'] ?? 0),
                    (int)($taData['mtf_data']['overall_confidence'] ?? 0),
                    (int)($taData['overall_confidence'] ?? 0)
                ),
            ];
        } else {
            $filterLog['mtf_strength'] = [
                'status' => 'single_tf_mode',
            ];
        }
        $adjustedConf = (int) ($taData['pre_trade_filter']['adjusted_confidence'] ?? $aiResult['confidence'] ?? 0);

        $tqData = $this->tradeQualityService->compute(
            action       : $action,
            entry        : $entry,
            tp2          : $tp2,
            sl           : $sl,
            rrValue      : $rrValue,
            adjustedConf : $adjustedConf,
            filterLog    : $filterLog,
            taData       : $taData,
        );

        // Apply the net confidence adjustment (R:R delta + EV delta, capped [-15, +5])
        $currentConf    = (int) ($aiResult['confidence'] ?? 0);
        $confAdjustment = (int) ($tqData['confidence_adjustment'] ?? 0);
        $newConf        = max(0, min(100, $currentConf + $confAdjustment));

        // Embed adjustment note if confidence changed
        if ($confAdjustment !== 0) {
            $sign = $confAdjustment > 0 ? '+' : '';
            $tqData['rr_ev_note'] = "Phase 3 applied {$sign}{$confAdjustment}% confidence adjustment "
                . "(R:R: {$tqData['rr_quality']['rr_label']}, EV: {$tqData['ev']['ev_display']}).";
        }

        return [
            'adjusted_confidence' => $newConf,
            'trade_quality_data'  => $tqData,
        ];
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

    /**
     * Compare the active liquidity sweep's direction against the FINAL trade
     * action (not the pre-AI overall_bias proxy PreTradeFilterService uses) and
     * apply a corrective confidence penalty + reasoning note if they conflict.
     * Bullish sweep + BUY, or bearish sweep + SELL → no correction needed (the
     * upstream bias-based bonus was almost certainly already right). Bullish
     * sweep + SELL, or bearish sweep + BUY → penalize, since the action ended up
     * counter to the sweep regardless of what the pre-AI bias bonus assumed.
     */
    private function applySweepActionConsistencyCheck(array $aiResult, array $taData): array
    {
        $action = $aiResult['action'] ?? 'WAIT';
        if (!in_array($action, ['BUY', 'SELL'])) {
            return $aiResult;
        }

        $activeSweep = $taData['liquidity_sweeps']['active_sweep'] ?? null;
        if (!$activeSweep) {
            return $aiResult;
        }

        $sweepDir    = strtolower($activeSweep['direction'] ?? '');
        $sweepBonus  = (int) ($activeSweep['confidence_boost'] ?? 0);
        $sweepType   = $activeSweep['type'] ?? 'sweep';
        if (!$sweepDir || $sweepBonus <= 0) {
            return $aiResult;
        }

        $actionDirection = $action === 'BUY' ? 'bullish' : 'bearish';

        if ($sweepDir !== $actionDirection) {
            $aiResult['confidence'] = max(0, (int) ($aiResult['confidence'] ?? 0) - $sweepBonus);
            $aiResult['reasoning']  = trim(($aiResult['reasoning'] ?? '') .
                " [⚠️ Liquidity Sweep vs. Action Conflict: Recent {$sweepType} points {$sweepDir}, "
                . "but final action is {$action} — confidence penalized {$sweepBonus}% instead of boosted.]");
            Log::info('Sweep/action conflict penalty applied', [
                'symbol' => $taData['symbol'] ?? '?', 'action' => $action,
                'sweep_direction' => $sweepDir, 'penalty' => $sweepBonus,
            ]);
        }

        return $aiResult;
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
        array  $constraints,
        float  $minRR = 2.0,
        ?string $timeframe = null
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

        // Regime-aware ATR step for TP fallback
        $atrPct      = ($atr > 0 && $price > 0) ? ($atr / $price) * 100 : 0;
        $regime      = $this->getVolatilityRegime($atrPct, $timeframe);
        $tpStepMult  = $this->getAtrMultipliers($regime, $timeframe)['tp_step'];
        $atrStep     = $atr > 0 ? $atr * $tpStepMult : 0;
        $slMultDet   = $this->getAtrMultipliers($regime, $timeframe)['sl'];
        // Clearance placed just beyond a REAL structure level (support/resistance/OB)
        // so the stop isn't sitting exactly on the level. This must stay small — the
        // regime's full $slMultDet (2-3x ATR) is the entire stop distance for the
        // no-structure fallback below; reusing it here as a "buffer" on top of an
        // already-real structure distance double-counted a full ATR-multiple stop,
        // which almost always blew past $maxSlDistDet and silently collapsed every
        // structure-anchored stop back into the same flat ATR distance regardless of
        // where the actual level was.
        $structureBuffer = $atr * 0.3;
        // Max distance a structure-anchored SL is allowed to sit from price — without
        // this, a distant support/OB level + buffer can produce an unbounded stop
        // (observed: 6.27x ATR on a real report). See VolatilityRegime::maxSlMultiplier.
        $maxSlDistDet = $atr > 0 ? $atr * \App\Support\VolatilityRegime::maxSlMultiplier($regime, $timeframe) : null;

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
            $atrBuffer = max($structureBuffer, $price * $slRatio * 0.5);
            $sl = !empty($slCandidates)
                ? min($slCandidates[0] + $atrBuffer, $price + $maxDist)
                : $price + ($price * $slRatio);
            if ($maxSlDistDet !== null) {
                $sl = min($sl, $price + $maxSlDistDet);
            }

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
            $atrBuffer = max($structureBuffer, $price * $slRatio * 0.5);
            $sl = !empty($slCandidates)
                ? max($slCandidates[0] - $atrBuffer, $price - $maxDist)
                : $price - ($price * $slRatio);
            if ($maxSlDistDet !== null) {
                $sl = max($sl, $price - $maxSlDistDet);
            }
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

        // SL distance for R:R-aware TP fallback synthesis
        $slDist       = abs($price - $sl);
        $minTP1FallDist = max($slDist * $minRR, $atrStep > 0 ? $atrStep : $minStep * 2);

        // Synthesize missing TPs using ATR-steps (regime-aware) instead of fixed %
        if ($tp1 === null) {
            $tp1 = $action === 'SELL' ? $price - $minTP1FallDist : $price + $minTP1FallDist;
        }
        if ($tp2 === null) {
            $step2 = $atrStep > 0 ? $atrStep : $minStep * 2;
            $tp2 = $action === 'SELL' ? $tp1 - $step2 : $tp1 + $step2;
        }
        if ($tp3 === null) {
            $step3 = $atrStep > 0 ? $atrStep * 1.5 : $minStep * 2;
            $tp3 = $action === 'SELL' ? $tp2 - $step3 : $tp2 + $step3;
        }

        // Enforce minStep ordering/spacing BEFORE clamping to max distance, then
        // clamp from the far leg inward (propagating the cap through the spacing)
        // rather than clamping first and re-adding spacing afterwards — the old
        // clamp-then-space order could push TP2/TP3 back out past the ceiling/floor
        // it had just been clamped to (e.g. TP1 pinned at ceiling, then
        // "tp2 = tp1 + minStep" put TP2 beyond the ceiling again), producing a
        // TP target farther than the timeframe's own max distance allowed and an
        // inflated, unreachable-in-practice R:R (reproduced live on REUSDT).
        $floor   = $price - $maxDist;
        $ceiling = $price + $maxDist;
        if ($action === 'SELL') {
            // Ensure TP1 > TP2 > TP3 for SELL
            if ($tp2 >= $tp1) $tp2 = $tp1 - $minStep;
            if ($tp3 >= $tp2) $tp3 = $tp2 - $minStep;
            // Clamp from the farthest leg (TP3) inward so spacing survives the clamp
            $tp3 = max($tp3, $floor);
            $tp2 = max($tp2, $tp3 + $minStep);
            $tp1 = max($tp1, $tp2 + $minStep);
        } else {
            // Ensure TP1 < TP2 < TP3 for BUY
            if ($tp2 <= $tp1) $tp2 = $tp1 + $minStep;
            if ($tp3 <= $tp2) $tp3 = $tp2 + $minStep;
            // Clamp from the farthest leg (TP3) inward so spacing survives the clamp
            $tp3 = min($tp3, $ceiling);
            $tp2 = min($tp2, $tp3 - $minStep);
            $tp1 = min($tp1, $tp2 - $minStep);
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
     *
     * @param float|null $atrPct Current ATR as a % of price (e.g. 1.05 for
     *   1.05%). When provided, the 1m/5m max_tp3 ceiling is widened to stay
     *   ATR-relative instead of a flat 2%. A flat 2% ceiling combined with
     *   ATR-scaled SL meant any coin with 5m ATR% above ~0.67% could
     *   mathematically never reach the 1:1.5 R:R minimum — the ceiling was
     *   systematically excluding the coins that actually move (real report:
     *   MMTUSDT/HOMEUSDT/VICUSDT all rejected on "R:R unachievable" despite a
     *   confirmed bias, because 2% was the hard cap regardless of ATR).
     */
    /** Public so ScanMarketOpportunitiesJob can reuse the same tuned per-timeframe
     *  TP/SL constraints for its "if not WAIT, leans X" hypothetical-level hint. */
    public function getTimeframeConstraints(string $timeframe, ?float $atrPct = null): array
    {
        $constraints = match ($timeframe) {
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

        if (in_array($timeframe, ['1m', '5m'], true) && $atrPct !== null && $atrPct > 0) {
            // 8x, not 6x — 6/maxSlMultiplier collapses to a near-constant R:R ceiling
            // regardless of ATR (both sides scale with ATR once this branch binds), and
            // 6/4.5 (very_high band) = 1.33 < GATE_MIN_RR (1.5), permanently locking out
            // any high-ATR 5m coin from ever clearing ScalpingMtfGate's R:R gate no
            // matter how strong the setup (reproduced live: HOMEUSDT, ATR 1.079%,
            // confidence 90, all four schools aligned). 8 must clear
            // maxSlMultiplier(very_high)=4.5 × GATE_MIN_RR=1.5 = 6.75 with headroom to
            // spare — 8/4.5=1.78, 8/4.0=2.00, 8/3.5=2.29 across all bands.
            $constraints['max_tp3'] = max($constraints['max_tp3'], 8 * ($atrPct / 100));
        }

        return $constraints;
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

    // ─────────────────────────────────────────────────────────────────────────
    //  Phase 2: Volatility-Regime Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getVolatilityRegime(float $atrPct, ?string $timeframe = null): string
    {
        return \App\Support\VolatilityRegime::classify($atrPct, $timeframe);
    }

    private function getAtrMultipliers(string $regime, ?string $timeframe = null): array
    {
        return \App\Support\VolatilityRegime::multipliers($regime, $timeframe);
    }

    /**
     * A TP that sits inside (or beyond) an unfilled, opposing-direction Fair
     * Value Gap is not a realistic target: price has to punch through
     * unconfirmed liquidity to get there. Pull the TP back to just before the
     * near edge of that FVG instead. For BUY, a bearish FVG between entry and
     * TP is blocking; for SELL, a bullish FVG is blocking.
     */
    private function clampTpAgainstBlockingFvg(string $action, float $entry, ?float $tp, array $taData): ?float
    {
        if ($tp === null) return $tp;

        $fvgs = $taData['smc']['fair_value_gaps'] ?? [];
        $blockingType = $action === 'BUY' ? 'bearish' : 'bullish';
        $nearestEdge = null;

        foreach ($fvgs as $fvg) {
            if (!empty($fvg['filled'])) continue;
            if (strtolower($fvg['type'] ?? '') !== $blockingType) continue;

            $top    = (float) ($fvg['top']    ?? 0);
            $bottom = (float) ($fvg['bottom'] ?? 0);
            if ($top <= 0 || $bottom <= 0) continue;

            if ($action === 'BUY') {
                // Blocking if the FVG sits between entry and TP (or TP lands inside it)
                if ($bottom > $entry && $bottom <= $tp) {
                    $nearestEdge = $nearestEdge === null ? $bottom : min($nearestEdge, $bottom);
                }
            } else {
                if ($top < $entry && $top >= $tp) {
                    $nearestEdge = $nearestEdge === null ? $top : max($nearestEdge, $top);
                }
            }
        }

        if ($nearestEdge === null) {
            return $tp;
        }

        // Pull TP back to just before the near edge (small buffer so it doesn't
        // land exactly on the boundary).
        $buffer = abs($nearestEdge - $entry) * 0.02;
        $clamped = $action === 'BUY' ? round($nearestEdge - $buffer, 8) : round($nearestEdge + $buffer, 8);

        // Never clamp to below/above entry — if the nearest blocking FVG is too
        // close to entry to leave room, leave the original TP alone (findStructureTP/
        // R:R gate will already have rejected an unreasonably close level elsewhere).
        if (($action === 'BUY' && $clamped <= $entry) || ($action === 'SELL' && $clamped >= $entry)) {
            return $tp;
        }

        return $clamped;
    }

    private function findStructureTP(
        string $action,
        float  $entry,
        float  $minDistance,
        array  $taData
    ): ?float {
        $supports    = collect($taData['classical']['support_resistance']['support']    ?? [])
                         ->pluck('price')->map(fn($p) => (float)$p)->toArray();
        $resistances = collect($taData['classical']['support_resistance']['resistance'] ?? [])
                         ->pluck('price')->map(fn($p) => (float)$p)->toArray();
        $orderBlocks = $taData['smc']['order_blocks']    ?? [];
        $fvgs        = $taData['smc']['fair_value_gaps'] ?? [];
        $poc         = (float) ($taData['volume_profile']['key_levels']['poc'] ?? 0);
        $vah         = (float) ($taData['volume_profile']['key_levels']['vah'] ?? 0);
        $val         = (float) ($taData['volume_profile']['key_levels']['val'] ?? 0);

        $isAbove   = ($action === 'BUY');
        $minTarget = $isAbove ? $entry + $minDistance : $entry - $minDistance;
        $maxSearch = $entry * 0.12;
        $maxTarget = $isAbove ? $entry + $maxSearch : $entry - $maxSearch;

        $candidates = [];

        foreach ($isAbove ? $resistances : $supports as $p) {
            if ($isAbove  && $p >= $minTarget && $p <= $maxTarget) $candidates[] = (float)$p;
            if (!$isAbove && $p <= $minTarget && $p >= $maxTarget) $candidates[] = (float)$p;
        }
        foreach ($orderBlocks as $ob) {
            $mid = ((float)($ob['bottom'] ?? 0) + (float)($ob['top'] ?? 0)) / 2;
            if ($mid <= 0) continue;
            if ($isAbove  && $mid >= $minTarget && $mid <= $maxTarget) $candidates[] = $mid;
            if (!$isAbove && $mid <= $minTarget && $mid >= $maxTarget) $candidates[] = $mid;
        }
        foreach ($fvgs as $fvg) {
            $mid = ((float)($fvg['bottom'] ?? 0) + (float)($fvg['top'] ?? 0)) / 2;
            if ($mid <= 0) continue;
            if ($isAbove  && $mid >= $minTarget && $mid <= $maxTarget) $candidates[] = $mid;
            if (!$isAbove && $mid <= $minTarget && $mid >= $maxTarget) $candidates[] = $mid;
        }
        foreach ([$poc, $vah, $val] as $vp) {
            if ($vp <= 0) continue;
            if ($isAbove  && $vp >= $minTarget && $vp <= $maxTarget) $candidates[] = $vp;
            if (!$isAbove && $vp <= $minTarget && $vp >= $maxTarget) $candidates[] = $vp;
        }

        if (empty($candidates)) return null;

        usort($candidates, fn($a, $b) => abs($a - $minTarget) <=> abs($b - $minTarget));
        return round($candidates[0], 8);
    }
}

