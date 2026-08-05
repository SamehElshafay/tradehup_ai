<?php
namespace App\Jobs;
use App\Models\Coin;
use App\Services\PythonTAService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCoinsJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 120;

    public function handle(PythonTAService $taService): void {
        try {
            // Binance's /ticker/24hr is a single HTTP call regardless of the requested
            // limit, so ask for effectively "all" USDT pairs instead of capping at 100 —
            // the old 100-coin cap meant most coins the scanner discovers (trending,
            // volume anomalies, etc. — usually outside the top 100 by volume) never
            // got synced at all, leaving price_change_24h/market_cap/high_24h/low_24h
            // stuck at their DB defaults forever.
            $binanceCoins = $taService->getTopCoins(3000);

            // Binance has no market_cap concept (it's an exchange, not a data
            // aggregator), so pull it from CoinGecko's free public endpoint instead.
            $marketCapByBase = $this->fetchCoinGeckoMarketCaps();

            $synced = 0;
            foreach ($binanceCoins as $coinData) {
                $baseAsset = str_replace('USDT', '', $coinData['symbol']);
                $update = [
                    'name'             => $baseAsset,
                    'base_asset'       => $baseAsset,
                    'quote_asset'      => 'USDT',
                    'current_price'    => $coinData['price'],
                    'price_change_24h' => $coinData['price_change_24h'],
                    'volume_24h'       => $coinData['volume_24h'],
                    'high_24h'         => $coinData['high_24h'],
                    'low_24h'          => $coinData['low_24h'],
                    'is_active'        => true,
                    'last_synced_at'   => now(),
                ];

                if (isset($marketCapByBase[strtoupper($baseAsset)])) {
                    $update['market_cap'] = $marketCapByBase[strtoupper($baseAsset)];
                }

                Coin::updateOrCreate(['symbol' => $coinData['symbol']], $update);
                $synced++;
            }

            Log::info('Coins synced successfully', [
                'count'             => $synced,
                'market_caps_found' => count($marketCapByBase),
            ]);
        } catch (\Exception $e) {
            Log::error('SyncCoinsJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * CoinGecko's free public /coins/markets endpoint (no API key required).
     * Four pages of 250 covers the top 1000 coins by market cap — Binance only
     * lists ~600-700 USDT pairs total, so this now overlaps almost all of them
     * (two pages/500 was leaving a meaningful chunk of mid-cap Binance coins
     * unmatched). Free public rate limit is 5-15 req/min, so 4 calls/hour is
     * nowhere near it.
     *
     * @return array<string, float> base symbol (uppercase) => market cap in USD
     */
    private function fetchCoinGeckoMarketCaps(): array {
        $map = [];
        try {
            for ($page = 1; $page <= 4; $page++) {
                $response = Http::withoutVerifying()->timeout(15)
                    ->get('https://api.coingecko.com/api/v3/coins/markets', [
                        'vs_currency' => 'usd',
                        'order'       => 'market_cap_desc',
                        'per_page'    => 250,
                        'page'        => $page,
                        'sparkline'   => false,
                    ]);

                if (!$response->successful()) {
                    Log::warning('CoinGecko market cap fetch failed', ['status' => $response->status(), 'page' => $page]);
                    break;
                }

                foreach ($response->json() as $c) {
                    if (!empty($c['symbol']) && isset($c['market_cap'])) {
                        $map[strtoupper($c['symbol'])] = $c['market_cap'];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('CoinGecko market cap fetch exception', ['error' => $e->getMessage()]);
        }
        return $map;
    }
}
