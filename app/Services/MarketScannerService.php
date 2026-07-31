<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketScannerService {
    
    /**
     * Fetch trending coins from CoinGecko.
     * Returns an array of symbols (e.g., ['BTCUSDT', 'SOLUSDT', 'PEPEUSDT']).
     */
    public function getTrendingCoins(int $limit = 10): array {
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
                    $trendingSymbols[] = $symbol . 'USDT';
                }
            }

            return $trendingSymbols;

        } catch (\Exception $e) {
            Log::error('Error fetching trending coins', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
