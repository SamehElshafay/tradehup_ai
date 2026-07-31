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
            // 1. Get Top Trending Coins
            event(new MarketScanProgress('Looking for trending coins...', 'info'));
            $trendingSymbols = $scanner->getTrendingCoins(15); // Scan top 15 trending coins

            if (empty($trendingSymbols)) {
                event(new MarketScanProgress('No trending coins found to scan.', 'warning'));
                Log::info('No trending coins found to scan.');
                return;
            }

            // 1.5. Cross-check against real Binance liquid pairs — drops trending tickers that
            // don't actually have a liquid USDT pair (ticker collisions, delisted, illiquid).
            $liquidSymbols = $this->getLiquidBinanceSymbols($taService);
            $validSymbols  = array_values(array_filter(
                $trendingSymbols,
                fn($symbol) => isset($liquidSymbols[$symbol])
            ));
            $skipped = array_diff($trendingSymbols, $validSymbols);
            if (!empty($skipped)) {
                Log::info('Skipping trending symbols without a liquid Binance USDT pair', ['skipped' => array_values($skipped)]);
                event(new MarketScanProgress('Skipped ' . count($skipped) . ' trending coin(s) with no liquid Binance pair: ' . implode(', ', $skipped), 'warning'));
            }

            if (empty($validSymbols)) {
                event(new MarketScanProgress('No liquid trending coins left to scan after filtering.', 'warning'));
                Log::info('No liquid trending coins left to scan.');
                return;
            }
            $trendingSymbols = $validSymbols;

            event(new MarketScanProgress('Found ' . count($trendingSymbols) . ' trending coins to analyze.', 'success'));
            Log::info('Found trending coins', ['symbols' => $trendingSymbols]);

            $totalCoins = count($trendingSymbols);
            $currentIndex = 1;
            $pendingAntigravityJobs = [];

            foreach ($trendingSymbols as $symbol) {
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
                $request->merge(['is_trending' => true]);

                event(new MarketScanProgress("Analyzing {$symbol} ({$timeframe}) with AI... ({$currentIndex}/{$totalCoins})", 'info', $symbol));
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
                        ];
                    } else {
                        if ($response->getStatusCode() === 200) {
                            $hasOpp = (isset($data['recommendation']) && $data['recommendation']['action'] !== 'WAIT') ? 'Yes!' : 'No';
                            event(new MarketScanProgress("Successfully analyzed {$symbol}. Found opportunity? {$hasOpp}", 'success', $symbol));
                            Log::info("Successfully analyzed trending coin: {$symbol}");
                        } else {
                            event(new MarketScanProgress("Failed to analyze {$symbol}.", 'warning', $symbol));
                            Log::warning("Failed to analyze trending coin: {$symbol}");
                        }
                    }
                } catch (\Exception $e) {
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
                        
                        $request = new Request();
                        $request->merge(['is_trending' => true]);
                        
                        try {
                            $statusResponse = $analyzer->bridgeStatus($request, $symbol, $timeframe, $requestId);
                            $statusData = json_decode($statusResponse->getContent(), true);
                            
                            if (isset($statusData['status'])) {
                                if ($statusData['status'] === 'completed') {
                                    $hasOpp = (isset($statusData['recommendation']) && $statusData['recommendation']['action'] !== 'WAIT') ? 'Yes!' : 'No';
                                    event(new MarketScanProgress("Successfully analyzed {$symbol} with Antigravity AI. Found opportunity? {$hasOpp}", 'success', $symbol));
                                    Log::info("Successfully analyzed trending coin: {$symbol} via Antigravity");
                                    unset($pendingAntigravityJobs[$index]);
                                } elseif ($statusData['status'] === 'failed') {
                                    event(new MarketScanProgress("Antigravity AI failed to analyze {$symbol}.", 'error', $symbol));
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
        $topCoins = $taService->getTopCoins(300);
        $map = [];
        foreach ($topCoins as $coin) {
            if (($coin['volume_24h'] ?? 0) >= self::MIN_VOLUME_USDT) {
                $map[$coin['symbol']] = $coin['volume_24h'];
            }
        }
        return $map;
    }
}
