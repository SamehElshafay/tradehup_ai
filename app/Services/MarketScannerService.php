<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketScannerService {

    /** Stores the number of valid USDT tickers fetched from Binance before strategy filters. */
    private int $lastRawCount = 0;

    public function getLastRawCount(): int {
        return $this->lastRawCount;
    }
    
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
     * Fetch Binance tickers (24h or rolling window) and apply Smart Money strategies.
     * @param string $volumeWindow  '24h' uses /ticker/24hr (standard), '1h'/'4h'/etc. uses /ticker?windowSize=X (rolling)
     * Returns an array of arrays: [['symbol' => 'BTCUSDT', 'strategy' => 'Volume Anomaly'], ...]
     */
    public function getBinanceOpportunities(
        int $volumeLimit = 10,
        int $earlyLimit = 10,
        int $reversalLimit = 10,
        bool $volumeEnabled = true,
        bool $earlyEnabled = true,
        bool $reversalEnabled = true,
        float $minVolumeUsdt = 2000000,
        float $volumeMinChange = 1.0,
        float $volumeMaxChange = 5.0,
        float $earlyMinChange = 5.0,
        float $earlyMaxChange = 10.0,
        float $reversalMinDump = -10.0,
        float $reversalMinBounce = 2.0,
        string $volumeWindow = '24h'
    ): array {
        try {
            // Choose endpoint based on window — 24h uses the dedicated (cheaper) endpoint,
            // any other window uses the rolling-window endpoint which supports 1m → 7d.
            if ($volumeWindow === '24h') {
                $url      = 'https://api.binance.com/api/v3/ticker/24hr';
                $response = Http::withoutVerifying()->timeout(15)->get($url);
            } else {
                // Rolling window: 1h, 4h, 12h, etc.
                $url      = 'https://api.binance.com/api/v3/ticker';
                $response = Http::withoutVerifying()->timeout(20)->get($url, ['windowSize' => $volumeWindow]);
            }

            if (!$response->successful()) {
                Log::warning('Failed to fetch ticker from Binance', ['window' => $volumeWindow]);
                return [];
            }

            $tickers = $response->json();
            
            // Filter to only active USDT pairs with solid volume
            $validTickers = array_filter($tickers, function($t) use ($minVolumeUsdt) {
                return str_ends_with($t['symbol'], 'USDT') 
                    && !str_contains($t['symbol'], 'UPUSDT') 
                    && !str_contains($t['symbol'], 'DOWNUSDT') 
                    && (float)$t['quoteVolume'] > $minVolumeUsdt;
            });

            // Store the raw count before applying strategy filters
            $this->lastRawCount = count($validTickers);

            $volumeAnomalies = [];
            $earlyMovers = [];
            $reversalCatchers = [];

            foreach ($validTickers as $t) {
                $change = (float)$t['priceChangePercent'];
                $lastPrice = (float)$t['lastPrice'];
                $lowPrice = (float)$t['lowPrice'];

                // Strategy 1: Volume Anomaly
                if ($volumeEnabled && $change >= $volumeMinChange && $change <= $volumeMaxChange) {
                    $volumeAnomalies[] = $t;
                }

                // Strategy 2: Early Movers (Started moving up nicely)
                if ($earlyEnabled && $change > $earlyMinChange && $change <= $earlyMaxChange) {
                    $earlyMovers[] = $t;
                }

                // Strategy 3: Reversal Catchers (Dumped beyond threshold, but bounced off the low)
                if ($reversalEnabled && $change < $reversalMinDump) {
                    $bouncePercent = (($lastPrice - $lowPrice) / $lowPrice) * 100;
                    if ($bouncePercent >= $reversalMinBounce) {
                        $reversalCatchers[] = $t;
                    }
                }
            }

            // Sort by quoteVolume descending — most liquid first
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

