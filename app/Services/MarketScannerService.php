<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketScannerService {
    
    /**
     * Fetch trending coins from CoinGecko.
     * Returns an array of arrays: [['symbol' => 'BTCUSDT', 'strategy' => 'Old'], ...]
     */
    public function getTrendingCoins(int $limit = 15): array {
        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->get("https://api.coingecko.com/api/v3/search/trending");

            if (!$response->successful()) {
                Log::warning('Failed to fetch trending coins from CoinGecko', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();
            $coins = $data['coins'] ?? [];
            
            $trendingSymbols = [];
            foreach ($coins as $index => $item) {
                if ($index >= $limit) break;
                // We append USDT to make it a standard Binance pair.
                $symbol = strtoupper($item['item']['symbol']);
                // Avoid coins with non-standard names or too long
                if (strlen($symbol) <= 6) {
                    $trendingSymbols[] = [
                        'symbol' => $symbol . 'USDT',
                        'strategy' => 'Old'
                    ];
                }
            }

            return $trendingSymbols;

        } catch (\Exception $e) {
            Log::error('Error fetching trending coins', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch all Binance 24h tickers and apply Smart Money strategies.
     * Returns an array of arrays: [['symbol' => 'BTCUSDT', 'strategy' => 'Volume Anomaly'], ...]
     */
    public function getBinanceOpportunities(
        int $volumeLimit = 10,
        int $earlyLimit = 10,
        int $reversalLimit = 10,
        bool $volumeEnabled = true,
        bool $earlyEnabled = true,
        bool $reversalEnabled = true
    ): array {
        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->get("https://api.binance.com/api/v3/ticker/24hr");

            if (!$response->successful()) {
                Log::warning('Failed to fetch 24h ticker from Binance');
                return [];
            }

            $tickers = $response->json();
            
            // Filter to only active USDT pairs with solid volume
            $validTickers = array_filter($tickers, function($t) {
                return str_ends_with($t['symbol'], 'USDT') 
                    && !str_contains($t['symbol'], 'UPUSDT') 
                    && !str_contains($t['symbol'], 'DOWNUSDT') 
                    && (float)$t['quoteVolume'] > 2000000; // at least 2m USDT volume
            });

            $volumeAnomalies = [];
            $earlyMovers = [];
            $reversalCatchers = [];

            foreach ($validTickers as $t) {
                $change = (float)$t['priceChangePercent'];
                $lastPrice = (float)$t['lastPrice'];
                $lowPrice = (float)$t['lowPrice'];

                // Strategy 1: Volume Anomaly (Price barely moved, but huge volume)
                if ($volumeEnabled && $change >= 1 && $change <= 5) {
                    $volumeAnomalies[] = $t;
                }

                // Strategy 2: Early Movers (Started moving up nicely)
                if ($earlyEnabled && $change > 5 && $change <= 10) {
                    $earlyMovers[] = $t;
                }

                // Strategy 3: Reversal Catchers (Dumped > 10%, but bounced off the low by at least 2%)
                if ($reversalEnabled && $change < -10) {
                    $bouncePercent = (($lastPrice - $lowPrice) / $lowPrice) * 100;
                    if ($bouncePercent >= 2) {
                        $reversalCatchers[] = $t;
                    }
                }
            }

            // Sort them by quoteVolume descending to get the most liquid/impactful ones
            $sortByVolume = function($a, $b) {
                return (float)$b['quoteVolume'] <=> (float)$a['quoteVolume'];
            };

            usort($volumeAnomalies, $sortByVolume);
            usort($earlyMovers, $sortByVolume);
            usort($reversalCatchers, $sortByVolume);

            // Take the top N from each strategy based on their specific limit
            $results = [];
            
            if ($volumeEnabled) {
                foreach (array_slice($volumeAnomalies, 0, $volumeLimit) as $t) {
                    $results[] = ['symbol' => $t['symbol'], 'strategy' => 'Volume Anomaly'];
                }
            }
            if ($earlyEnabled) {
                foreach (array_slice($earlyMovers, 0, $earlyLimit) as $t) {
                    $results[] = ['symbol' => $t['symbol'], 'strategy' => 'Early Mover'];
                }
            }
            if ($reversalEnabled) {
                foreach (array_slice($reversalCatchers, 0, $reversalLimit) as $t) {
                    $results[] = ['symbol' => $t['symbol'], 'strategy' => 'Reversal Catcher'];
                }
            }

            return $results;

        } catch (\Exception $e) {
            Log::error('Error fetching Binance opportunities', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

