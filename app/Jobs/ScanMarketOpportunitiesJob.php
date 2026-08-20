<?php
namespace App\Jobs;

use App\Models\Coin;
use App\Services\MarketScannerService;
use App\Services\PythonTAService;
use App\Http\Controllers\Api\AnalysisController;
use App\Events\MarketScanCompleted;
use App\Events\MarketScanProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class ScanMarketOpportunitiesJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public $timeout = 3600; // 1 hour

    /** Minimum 24h quote volume (USDT) for a trending coin to be considered liquid enough to analyze. */
    private const MIN_VOLUME_USDT = 500_000;

    /** Maximum time this entire job is allowed to run (1 hour) before gracefully exiting. */
    private const TIME_BUDGET_SECONDS = 3600;

    /**
     * Execute the job.
     */
    public function handle(MarketScannerService $scanner, AnalysisController $analyzer, PythonTAService $taService): void {
        ini_set('max_execution_time', 3600); // 1 hour limit for this script
        $startedAt = time();

        $timeframe = $this->getScanTimeframe();

        // Flag that a scan is in progress so the frontend can show a persistent indicator
        // even if the tab was opened/refreshed after the scan already started. Expires on
        // its own after 15 min in case the job dies without reaching the finally block.
        \Illuminate\Support\Facades\Cache::put('ai_scan_running', true, now()->addMinutes(15));

        event(new MarketScanProgress('Starting automated market opportunity scan...', 'info'));
        Log::info('Starting automated market opportunity scan...', ['timeframe' => $timeframe]);
        try {
            // Read settings
            $settingsFile = storage_path('app/ai_settings.json');
            $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
            
            $trendEnabled   = $settings['strategy_trending_enabled'] ?? true;
            $trendLimit     = (int) ($settings['strategy_trending_limit'] ?? 20);
            
            $volEnabled     = $settings['strategy_volume_enabled'] ?? true;
            $volLimit       = (int) ($settings['strategy_volume_limit'] ?? 10);
            
            $earlyEnabled   = $settings['strategy_early_enabled'] ?? true;
            $earlyLimit     = (int) ($settings['strategy_early_limit'] ?? 10);
            
            $revEnabled     = $settings['strategy_reversal_enabled'] ?? true;
            $revLimit       = (int) ($settings['strategy_reversal_limit'] ?? 10);

            // Threshold settings (new — user-configurable)
            $minVolumeUsdt       = (float) ($settings['strategy_min_volume_usdt']    ?? 2000000);
            $volumeMinChange     = (float) ($settings['strategy_volume_min_change']  ?? 1.0);
            $volumeMaxChange     = (float) ($settings['strategy_volume_max_change']  ?? 5.0);
            $earlyMinChange      = (float) ($settings['strategy_early_min_change']   ?? 5.0);
            $earlyMaxChange      = (float) ($settings['strategy_early_max_change']   ?? 10.0);
            $reversalMinDump     = (float) ($settings['strategy_reversal_min_dump']  ?? -10.0);
            $reversalMinBounce   = (float) ($settings['strategy_reversal_min_bounce'] ?? 2.0);
            $volumeWindow        = (string) ($settings['strategy_volume_window']     ?? '24h');

            $activeStrategies = [];
            if ($trendEnabled) $activeStrategies[] = 'Trending';
            if ($volEnabled)   $activeStrategies[] = 'Volume Anomaly';
            if ($earlyEnabled) $activeStrategies[] = 'Early Mover';
            if ($revEnabled)   $activeStrategies[] = 'Reversal Catcher';
            
            $strategiesStr = empty($activeStrategies) ? 'None' : implode(', ', $activeStrategies);

            // 1. Get Coins to Scan
            event(new MarketScanProgress("Scanning for opportunities using: $strategiesStr...", 'info'));
            
            $trendingCoins = $trendEnabled ? $scanner->getTrendingCoins($trendLimit) : [];
            $binanceCoins  = $scanner->getBinanceOpportunities(
                $volLimit, $earlyLimit, $revLimit,
                $volEnabled, $earlyEnabled, $revEnabled,
                $minVolumeUsdt, $volumeMinChange, $volumeMaxChange,
                $earlyMinChange, $earlyMaxChange,
                $reversalMinDump, $reversalMinBounce,
                $volumeWindow
            );

            // Log how many raw USDT tickers were inspected before strategy filtering
            $rawBinanceCount = $scanner->getLastRawCount();
            if ($rawBinanceCount > 0) {
                event(new MarketScanProgress(
                    "Fetched {$rawBinanceCount} liquid USDT tickers from Binance [{$volumeWindow} window] before strategy filters.",
                    'info',
                    null, null, null, null, null, null,
                    $rawBinanceCount
                ));
            }
            
            // Merge and deduplicate by symbol (preferring new strategies over 'Old')
            $merged = [];
            foreach (array_merge($trendingCoins, $binanceCoins) as $item) {
                $sym = $item['symbol'];
                if (!isset($merged[$sym]) || $merged[$sym]['strategy'] === 'Old') {
                    $merged[$sym] = $item;
                }
            }
            $coinsToScan = array_values($merged);

            if (empty($coinsToScan)) {
                event(new MarketScanProgress('No coins found to scan.', 'warning'));
                Log::info('No coins found to scan.');
                return;
            }

            // 1.5. Cross-check against real Binance liquid pairs — drops tickers that
            // don't actually have a liquid USDT pair (ticker collisions, delisted, illiquid).
            $liquidSymbols = $this->getLiquidBinanceSymbols($taService);
            $validCoins = array_values(array_filter(
                $coinsToScan,
                fn($item) => isset($liquidSymbols[$item['symbol']])
            ));

            $skipped = array_diff(array_column($coinsToScan, 'symbol'), array_column($validCoins, 'symbol'));
            if (!empty($skipped)) {
                Log::info('Skipping symbols without a liquid Binance USDT pair', ['skipped' => array_values($skipped)]);
                event(new MarketScanProgress('Skipped ' . count($skipped) . ' coin(s) with no liquid Binance pair: ' . implode(', ', $skipped), 'warning'));
            }

            if (empty($validCoins)) {
                event(new MarketScanProgress('No liquid coins left to scan after filtering.', 'warning'));
                Log::info('No liquid coins left to scan.');
                return;
            }
            $coinsToScan = $validCoins;

            // Build a simple list to broadcast: [{ symbol, strategy }, ...]
            $coinsList = array_map(fn($c) => ['symbol' => $c['symbol'], 'strategy' => $c['strategy']], $coinsToScan);
            event(new MarketScanProgress(
                "Found " . count($coinsToScan) . " coins to analyze using: $strategiesStr.",
                'success',
                null, null, null, null, null,
                $coinsList,
                $rawBinanceCount
            ));
            Log::info('Found coins to analyze', ['coins' => $coinsToScan]);

            $totalCoins = count($coinsToScan);
            $currentIndex = 1;
            $pendingAntigravityJobs = [];

            foreach ($coinsToScan as $coinItem) {
                $symbol = $coinItem['symbol'];
                $strategy = $coinItem['strategy'];
                if (\Illuminate\Support\Facades\Cache::pull('stop_ai_scan')) {
                    Log::info('Market scan was halted by user.');
                    event(new MarketScanCompleted('Market scan was halted by user.'));
                    return;
                }

                // Stop starting new analyses if we're close to the job timeout, instead of
                // letting the scan get silently killed mid-way with no explanation.
                if ((time() - $startedAt) > self::TIME_BUDGET_SECONDS) {
                    $remaining = $totalCoins - $currentIndex + 1;
                    Log::warning('ScanMarketOpportunitiesJob stopping early to respect timeout budget', ['remaining_coins' => $remaining]);
                    event(new MarketScanProgress("Stopping scan early ({$remaining} coin(s) left unscanned) to avoid exceeding the time limit.", 'warning'));
                    break;
                }

                // 2. Ensure coin exists in our DB
                $baseAsset = str_replace('USDT', '', $symbol);
                $coin = Coin::firstOrCreate(
                    ['symbol' => $symbol],
                    [
                        'name' => $baseAsset,
                        'base_asset' => $baseAsset,
                        'quote_asset' => 'USDT',
                        'current_price' => 0, // Will be updated by analyzer
                        'is_active' => true,
                    ]
                );

                // 3. Trigger analysis for this trending coin, on the user-configured scan timeframe
                $request = new Request();
                $request->merge(['is_trending' => true, 'strategy' => $strategy]);

                $settingsFile = storage_path('app/ai_settings.json');
                $displayTimeframe = $timeframe;
                if (file_exists($settingsFile)) {
                    $settings = json_decode(file_get_contents($settingsFile), true);
                    $mtfToggleOn = (bool) ($settings['multi_timeframe'] ?? false);
                    $htfTf = $settings['mtf_htf'] ?? null;
                    $mtfTf = $settings['mtf_mtf'] ?? null;
                    $ltfTf = $settings['mtf_ltf'] ?? null;
                    // Must mirror AnalysisController::analyze()'s $mtfActive condition —
                    // the multi_timeframe toggle previously wasn't checked here either,
                    // so the scan progress message could claim "MTF: ..." while the
                    // toggle was off and the actual analysis ran single-timeframe.
                    if ($mtfToggleOn && $htfTf && $mtfTf && $ltfTf && $htfTf !== $mtfTf && $mtfTf !== $ltfTf && $htfTf !== $ltfTf) {
                        $displayTimeframe = "MTF: {$htfTf}→{$mtfTf}→{$ltfTf}";
                    }
                }

                event(new MarketScanProgress("Analyzing {$symbol} ({$displayTimeframe}) with AI... ({$currentIndex}/{$totalCoins})", 'info', $symbol, null, null, null, $strategy));
                Log::info("Analyzing trending coin: {$symbol}", ['timeframe' => $timeframe]);

                try {
                    // Call the analyze function
                    $response = $analyzer->analyze($request, $symbol, $timeframe);
                    $data = json_decode($response->getContent(), true);

                    if (isset($data['bridge_pending']) && $data['bridge_pending']) {
                        $pendingAntigravityJobs[] = [
                            'symbol' => $symbol,
                            'timeframe' => $timeframe,
                            'request_id' => $data['request_id'],
                            'strategy' => $strategy,
                        ];
                    } else {
                        if ($response->getStatusCode() === 200) {
                            $hasOpp = (isset($data['recommendation']) && $data['recommendation']['action'] !== 'WAIT') ? 'Yes!' : 'No';
                            $action = $data['recommendation']['action'] ?? 'WAIT';
                            $analysisId = $data['id'] ?? null; // Assuming analysis ID is returned in $data['id']

                            $progressMsg = "Successfully analyzed {$symbol}. Found opportunity? {$hasOpp}";
                            if ($action !== 'WAIT' && isset($data['recommendation'])) {
                                $rec = $data['recommendation'];
                                $parts = ["→ {$action}"];
                                if (isset($rec['confidence'])) $parts[] = "{$rec['confidence']}% conf";
                                if (!empty($rec['entry_price'])) $parts[] = "Entry: {$rec['entry_price']}";
                                if (!empty($rec['tp1'])) $parts[] = "TP1: {$rec['tp1']}";
                                if (!empty($rec['tp2'])) $parts[] = "TP2: {$rec['tp2']}";
                                if (!empty($rec['tp3'])) $parts[] = "TP3: {$rec['tp3']}";
                                if (!empty($rec['sl']))  $parts[] = "SL: {$rec['sl']}";
                                $progressMsg .= ' | ' . implode(' | ', $parts);
                            } elseif ($action === 'WAIT') {
                                // "If WAIT weren't an option" — the raw Classical+SMC+Harmonic+Volume
                                // bias is computed BEFORE the confidence-threshold gate turns it into
                                // WAIT, so the underlying lean is already sitting in raw_data. Surface
                                // it here as a below-threshold hint, not a real recommendation.
                                $rawBias  = $data['analysis']['raw_data']['overall_bias'] ?? null;
                                $rawConf  = $data['analysis']['raw_data']['overall_confidence'] ?? null;
                                $rawPrice = (float) ($data['analysis']['raw_data']['current_price'] ?? 0);
                                if (in_array(strtolower((string) $rawBias), ['bullish', 'bearish']) && $rawPrice > 0) {
                                    $lean   = strtolower($rawBias) === 'bullish' ? 'BUY' : 'SELL';
                                    $isBuy  = $lean === 'BUY';
                                    $c      = $analyzer->getTimeframeConstraints($timeframe);
                                    $tpStep = $rawPrice * $c['tp_step'];
                                    $slDist = $rawPrice * $c['sl_ratio'];
                                    $hypTp1 = $isBuy ? $rawPrice + $tpStep : $rawPrice - $tpStep;
                                    $hypSl  = $isBuy ? $rawPrice - $slDist : $rawPrice + $slDist;

                                    $progressMsg .= " | (if not WAIT, leans {$lean}" .
                                        ($rawConf !== null ? ", {$rawConf}% raw confidence" : '') .
                                        " — below threshold) | Entry: {$rawPrice} | TP1: " . round($hypTp1, 8) .
                                        " | SL: " . round($hypSl, 8);
                                }
                            }

                            event(new MarketScanProgress($progressMsg, 'success', $symbol, $action, $analysisId, null, $strategy));
                            Log::info("Successfully analyzed trending coin: {$symbol}");
                        } else {
                            $errorMsg = $data['message'] ?? $data['error'] ?? 'Unknown error';
                            event(new MarketScanProgress("Failed to analyze {$symbol}.", 'warning', $symbol, 'ERROR', null, $errorMsg, $strategy));
                            Log::warning("Failed to analyze trending coin: {$symbol}");
                        }
                    }
                } catch (\Exception $e) {
                    event(new MarketScanProgress("Failed to analyze {$symbol}. System Error.", 'warning', $symbol, 'ERROR', null, $e->getMessage(), $strategy));
                    Log::error("Error analyzing trending coin {$symbol}", ['error' => $e->getMessage()]);
                }
                
                $currentIndex++;

                // Small pause to avoid rate limiting
                sleep(2);
            }

            if (!empty($pendingAntigravityJobs)) {
                $totalPending = count($pendingAntigravityJobs);
                event(new MarketScanProgress("Waiting for Antigravity AI to finish analyzing {$totalPending} coins concurrently...", 'info'));
                Log::info("Polling for pending Antigravity jobs", ['count' => $totalPending]);
                
                $pollAttempts = 0;
                while (!empty($pendingAntigravityJobs) && $pollAttempts < 240) { // Max 1200 seconds total (240 * 5s)
                    sleep(5);
                    $pollAttempts++;
                    
                    if ((time() - $startedAt) > self::TIME_BUDGET_SECONDS) {
                        Log::warning('ScanMarketOpportunitiesJob stopping early to respect timeout budget during polling');
                        event(new MarketScanProgress("Stopping scan early. Timeout reached while waiting for Antigravity.", 'warning'));
                        break;
                    }
                    
                    if (\Illuminate\Support\Facades\Cache::pull('stop_ai_scan')) {
                        Log::info('Market scan was halted by user during polling.');
                        event(new MarketScanCompleted('Market scan was halted by user.'));
                        return;
                    }

                    foreach ($pendingAntigravityJobs as $index => $job) {
                        $symbol = $job['symbol'];
                        $timeframe = $job['timeframe'];
                        $requestId = $job['request_id'];
                        $strategy = $job['strategy'];
                        
                        $request = new Request();
                        $request->merge(['is_trending' => true, 'strategy' => $strategy]);
                        
                        try {
                            $statusResponse = $analyzer->bridgeStatus($request, $symbol, $timeframe, $requestId);
                            $statusData = json_decode($statusResponse->getContent(), true);
                            
                            if (isset($statusData['status'])) {
                                if ($statusData['status'] === 'completed') {
                                    $hasOpp = (isset($statusData['recommendation']) && $statusData['recommendation']['action'] !== 'WAIT') ? 'Yes!' : 'No';
                                    $action = $statusData['recommendation']['action'] ?? 'WAIT';
                                    $analysisId = $statusData['id'] ?? null;
                                    
                                    event(new MarketScanProgress("Successfully analyzed {$symbol} with Antigravity AI. Found opportunity? {$hasOpp}", 'success', $symbol, $action, $analysisId, null, $strategy));
                                    Log::info("Successfully analyzed trending coin: {$symbol} via Antigravity");
                                    unset($pendingAntigravityJobs[$index]);
                                } elseif ($statusData['status'] === 'failed') {
                                    $errorMsg = $statusData['error'] ?? 'Antigravity AI failed.';
                                    event(new MarketScanProgress("Antigravity AI failed to analyze {$symbol}.", 'error', $symbol, 'ERROR', null, $errorMsg, $strategy));
                                    Log::error("Antigravity AI failed to analyze trending coin: {$symbol}");
                                    unset($pendingAntigravityJobs[$index]);
                                }
                            }
                        } catch (\Exception $e) {
                             Log::error("Error polling trending coin {$symbol}", ['error' => $e->getMessage()]);
                        }
                    }
                }
                
                if (!empty($pendingAntigravityJobs)) {
                    $remainingSymbols = array_column($pendingAntigravityJobs, 'symbol');
                    event(new MarketScanProgress("Timed out waiting for Antigravity AI on: " . implode(', ', $remainingSymbols), 'warning'));
                    Log::warning("Timed out waiting for Antigravity AI", ['symbols' => $remainingSymbols]);
                }
            }

            Log::info('Automated market scan completed.');
            event(new MarketScanCompleted('Market scan completed!'));
        } catch (\Exception $e) {
            Log::error('ScanMarketOpportunitiesJob failed', ['error' => $e->getMessage()]);
            event(new MarketScanCompleted('Market scan failed: ' . $e->getMessage()));
        } finally {
            \Illuminate\Support\Facades\Cache::forget('ai_scan_running');
        }
    }

    private function getScanTimeframe(): string {
        $settingsFile = storage_path('app/ai_settings.json');
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            if (!empty($settings['scan_timeframe'])) {
                return $settings['scan_timeframe'];
            }
        }
        return '4h';
    }

    /**
     * Returns a [symbol => volume_24h] map of real, liquid Binance USDT pairs.
     * Used to drop CoinGecko "trending" tickers that don't map to an actual liquid
     * Binance pair (ticker collisions, delisted symbols, or too illiquid to trade safely).
     */
    private function getLiquidBinanceSymbols(PythonTAService $taService): array {
        $topCoins = $taService->getTopCoins(1000);
        $map = [];
        foreach ($topCoins as $coin) {
            if (($coin['volume_24h'] ?? 0) >= self::MIN_VOLUME_USDT) {
                $map[$coin['symbol']] = $coin['volume_24h'];
            }
        }
        return $map;
    }
}
