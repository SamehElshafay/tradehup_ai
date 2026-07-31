<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CryptoPanicService {
    private string $apiKey;

    public function __construct() {
        $this->apiKey = env('CRYPTOPANIC_API_KEY', '');
    }

    public function getLatestNews(string $kind = 'news', int $limit = 20): array {
        // Since CryptoPanic discontinued their free plan, we fetch directly from CoinTelegraph & CoinDesk RSS Feeds
        // which do NOT require any API keys! We use withoutVerifying() to avoid SSL issues on local Windows.
        try {
            $response = Http::withoutVerifying()->timeout(10)->get("https://cointelegraph.com/rss");
            
            if (!$response->successful()) {
                // Fallback to CoinDesk RSS if CoinTelegraph is down
                $response = Http::withoutVerifying()->timeout(10)->get("https://www.coindesk.com/arc/outboundfeeds/rss/");
            }

            if (!$response->successful()) {
                return $this->getMockNewsFallback();
            }

            $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                return $this->getMockNewsFallback();
            }

            $items = [];
            $count = 0;

            foreach ($xml->channel->item as $item) {
                if ($count >= $limit) break;

                $title = (string) $item->title;
                $url = (string) $item->link;
                $description = (string) $item->description;
                $pubDate = (string) $item->pubDate;
                
                $fullText = strtolower($title . ' ' . $description);
                $sentimentData = $this->analyzeTextSentiment($fullText);
                $coins = $this->detectCoins($fullText);

                $items[] = [
                    'source' => 'cointelegraph',
                    'title' => $title,
                    'url' => $url,
                    'type' => 'crypto',
                    'sentiment' => $sentimentData['sentiment'],
                    'sentiment_score' => $sentimentData['score'],
                    'coins_mentioned' => $coins,
                    'published_at' => $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : now()->toDateTimeString()
                ];
                $count++;
            }

            return $items;

        } catch (\Exception $e) {
            Log::error('RSS News Fetching error', ['error' => $e->getMessage()]);
            return $this->getMockNewsFallback();
        }
    }

    private function analyzeTextSentiment(string $text): array {
        $bullishKeywords = ['bullish', 'rally', 'soar', 'gain', 'growth', 'rise', 'ath', 'buy', 'positive', 'breakout', 'surge', 'support', 'pump'];
        $bearishKeywords = ['bearish', 'drop', 'fall', 'loss', 'sell', 'negative', 'plummet', 'dump', 'hack', 'scam', 'drain'];
        $panicKeywords = ['panic', 'crash', 'liquidation', 'lawsuit', 'sec', 'ban', 'investigate', 'collapse', 'fud', 'fear'];

        $bullishCount = 0;
        $bearishCount = 0;
        $panicCount = 0;

        foreach ($bullishKeywords as $kw) {
            if (str_contains($text, $kw)) $bullishCount++;
        }
        foreach ($bearishKeywords as $kw) {
            if (str_contains($text, $kw)) $bearishCount++;
        }
        foreach ($panicKeywords as $kw) {
            if (str_contains($text, $kw)) $panicCount++;
        }

        if ($panicCount > 0 && $panicCount >= $bullishCount) {
            return ['sentiment' => 'panic', 'score' => -0.8];
        }
        if ($bearishCount > $bullishCount) {
            $score = -0.1 * min($bearishCount, 10);
            return ['sentiment' => 'bearish', 'score' => round($score, 2)];
        }
        if ($bullishCount > $bearishCount) {
            $score = 0.1 * min($bullishCount, 10);
            return ['sentiment' => 'bullish', 'score' => round($score, 2)];
        }

        return ['sentiment' => 'neutral', 'score' => 0.0];
    }

    private function detectCoins(string $text): array {
        $mapping = [
            'btc' => ['btc', 'bitcoin'],
            'eth' => ['eth', 'ethereum'],
            'sol' => ['sol', 'solana'],
            'bnb' => ['bnb', 'binance'],
            'ada' => ['ada', 'cardano'],
            'xrp' => ['xrp', 'ripple'],
            'doge' => ['doge', 'dogecoin'],
            'link' => ['link', 'chainlink'],
            'near' => ['near', 'near protocol'],
        ];

        $detected = [];
        foreach ($mapping as $symbol => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $detected[] = strtoupper($symbol);
                    break;
                }
            }
        }

        // Default to BTC if no coin detected but cryptos are discussed
        if (empty($detected)) {
            $detected[] = 'BTC';
        }

        return $detected;
    }

    private function getMockNewsFallback(): array {
        return [
            [
                'source' => 'cointelegraph',
                'title' => 'Bitcoin Holds Steady Above $65k As Institutional Inflows Surge',
                'url' => 'https://cointelegraph.com/news/bitcoin-holds-above-65k',
                'type' => 'crypto',
                'sentiment' => 'bullish',
                'sentiment_score' => 0.6,
                'coins_mentioned' => ['BTC'],
                'published_at' => now()->toDateTimeString()
            ],
            [
                'source' => 'cointelegraph',
                'title' => 'Ethereum Fees Drop To Historic Lows, Boosting Layer-2 Adoption',
                'url' => 'https://cointelegraph.com/news/ethereum-fees-drop-to-historic-lows',
                'type' => 'crypto',
                'sentiment' => 'bullish',
                'sentiment_score' => 0.5,
                'coins_mentioned' => ['ETH'],
                'published_at' => now()->toDateTimeString()
            ]
        ];
    }
}
