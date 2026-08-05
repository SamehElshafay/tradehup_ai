<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CryptoPanicService {
    private string $apiKey;

    // ── Comprehensive coin → keywords mapping ─────────────────────────────
    private const COIN_KEYWORDS = [
        'BTC'  => ['btc', 'bitcoin', 'satoshi', 'sats', 'xbt'],
        'ETH'  => ['eth', 'ethereum', 'ether', 'vitalik', 'eip'],
        'SOL'  => ['sol', 'solana', 'phantom'],
        'BNB'  => ['bnb', 'binance coin', 'binance smart chain', 'bsc'],
        'XRP'  => ['xrp', 'ripple', 'xrpledger'],
        'ADA'  => ['ada', 'cardano', 'charles hoskinson'],
        'DOGE' => ['doge', 'dogecoin', 'doggy'],
        'LINK' => ['link', 'chainlink', 'smartcon'],
        'AVAX' => ['avax', 'avalanche'],
        'DOT'  => ['dot', 'polkadot', 'substrate'],
        'MATIC'=> ['matic', 'polygon', 'pol'],
        'SHIB' => ['shib', 'shiba inu', 'shiba'],
        'LTC'  => ['ltc', 'litecoin'],
        'UNI'  => ['uni', 'uniswap'],
        'ATOM' => ['atom', 'cosmos hub', 'cosmos'],
        'NEAR' => ['near', 'near protocol'],
        'FTM'  => ['ftm', 'fantom'],
        'OP'   => ['optimism'],
        'ARB'  => ['arbitrum', 'arb'],
        'INJ'  => ['injective', 'inj'],
        'SUI'  => ['sui network'],
        'APT'  => ['aptos', 'apt'],
        'TIA'  => ['celestia', 'tia'],
        'JUP'  => ['jupiter', 'jup'],
        'WIF'  => ['dogwifhat', 'wif'],
        'PEPE' => ['pepe'],
        'BONK' => ['bonk'],
    ];

    // ── Enhanced sentiment lexicon ─────────────────────────────────────────
    private const BULLISH_KEYWORDS  = [
        'bullish', 'rally', 'soar', 'gain', 'growth', 'rise', 'ath', 'buy', 'positive',
        'breakout', 'surge', 'support', 'pump', 'adoption', 'launch', 'upgrade', 'integration',
        'partnership', 'etf', 'approve', 'halving', 'institutional', 'accumulate', 'rebound',
        'recovery', 'milestone', 'record', 'inflow', 'demand', 'mainstream',
    ];
    private const BEARISH_KEYWORDS  = [
        'bearish', 'drop', 'fall', 'loss', 'sell', 'negative', 'plummet', 'dump', 'hack',
        'scam', 'drain', 'exploit', 'vulnerability', 'outflow', 'withdraw', 'delisted',
        'restriction', 'sanction', 'fraud', 'rug', 'exit',
    ];
    private const PANIC_KEYWORDS    = [
        'panic', 'crash', 'liquidation', 'lawsuit', 'sec', 'ban', 'investigate', 'collapse',
        'fud', 'fear', 'bankrupt', 'insolvency', 'contagion', 'meltdown', 'crisis',
        'enforcement', 'seizure', 'suspended',
    ];

    // ── RSS news sources (primary + fallbacks) ────────────────────────────
    private const RSS_SOURCES = [
        ['url' => 'https://cointelegraph.com/rss',                              'name' => 'cointelegraph'],
        ['url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/',            'name' => 'coindesk'],
        ['url' => 'https://cryptonews.com/news/feed/',                          'name' => 'cryptonews'],
        ['url' => 'https://decrypt.co/feed',                                    'name' => 'decrypt'],
        ['url' => 'https://bitcoinmagazine.com/.rss/full/',                     'name' => 'bitcoin_magazine'],
    ];

    public function __construct() {
        $this->apiKey = env('CRYPTOPANIC_API_KEY', '');
    }

    // ── General market news (multi-source with cache) ─────────────────────
    public function getLatestNews(string $kind = 'news', int $limit = 20): array {
        $cacheKey = "crypto_news_general_{$limit}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $items = [];

            // Try each RSS source until we get enough news
            foreach (self::RSS_SOURCES as $source) {
                if (count($items) >= $limit) break;

                try {
                    $response = Http::withoutVerifying()->timeout(8)->get($source['url']);
                    if (!$response->successful()) continue;

                    $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                    if ($xml === false) continue;

                    $count = 0;
                    foreach ($xml->channel->item as $item) {
                        if (count($items) >= $limit) break;

                        $title       = (string) $item->title;
                        $url         = (string) $item->link;
                        $description = (string) $item->description;
                        $pubDate     = (string) $item->pubDate;

                        // Skip items older than 48h
                        if ($pubDate && (time() - strtotime($pubDate)) > 172800) continue;

                        $fullText     = strtolower($title . ' ' . $description);
                        $sentimentData = $this->analyzeTextSentiment($fullText);
                        $coins         = $this->detectCoins($fullText);

                        $items[] = [
                            'source'          => $source['name'],
                            'title'           => $title,
                            'url'             => $url,
                            'type'            => 'crypto',
                            'sentiment'       => $sentimentData['sentiment'],
                            'sentiment_score' => $sentimentData['score'],
                            'coins_mentioned' => $coins,
                            'published_at'    => $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : now()->toDateTimeString(),
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning("RSS source {$source['name']} failed", ['error' => $e->getMessage()]);
                    continue;
                }
            }

            if (empty($items)) {
                return $this->getMockNewsFallback();
            }

            // Sort by recency
            usort($items, fn($a, $b) => strtotime($b['published_at']) - strtotime($a['published_at']));
            $result = array_slice($items, 0, $limit);

            Cache::put($cacheKey, $result, now()->addMinutes(15));
            return $result;

        } catch (\Exception $e) {
            Log::error('RSS News Fetching error', ['error' => $e->getMessage()]);
            return $this->getMockNewsFallback();
        }
    }

    // ── Coin-specific news (multi-source: Google News + RSS filtered) ─────
    public function getCoinSpecificNews(string $symbol, int $limit = 5): array {
        $baseAsset = str_replace('USDT', '', strtoupper($symbol));
        $cacheKey  = "crypto_news_specific_{$baseAsset}_{$limit}";

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $items = [];

        // Source 1: Google News RSS (freshest, most relevant)
        try {
            $coinName = $this->getCoinFullName($baseAsset);
            $query    = urlencode("\"{$coinName}\" crypto OR \"{$baseAsset}\" crypto OR \"{$baseAsset}\" token when:2d");
            $response = Http::withoutVerifying()->timeout(10)
                ->get("https://news.google.com/rss/search?q={$query}&hl=en-US&gl=US&ceid=US:en");

            if ($response->successful()) {
                $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    $count = 0;
                    foreach ($xml->channel->item as $item) {
                        if ($count >= $limit) break;

                        $title   = (string) $item->title;
                        $url     = (string) $item->link;
                        $pubDate = (string) $item->pubDate;

                        // Must mention the coin
                        $lowerTitle = strtolower($title);
                        if (!$this->isCoinMentioned($lowerTitle, $baseAsset)) continue;

                        $sentimentData = $this->analyzeTextSentiment($lowerTitle);

                        $items[] = [
                            'source'          => 'google_news',
                            'title'           => $title,
                            'url'             => $url,
                            'sentiment'       => $sentimentData['sentiment'],
                            'sentiment_score' => $sentimentData['score'],
                            'published_at'    => $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : now()->toDateTimeString(),
                        ];
                        $count++;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Google News coin search failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        // Source 2: Filter general RSS sources for coin-specific items
        if (count($items) < $limit) {
            try {
                $generalNews = $this->getLatestNews('news', 50);
                $coinSpecific = array_filter(
                    $generalNews,
                    fn($n) => in_array($baseAsset, $n['coins_mentioned'] ?? [])
                );
                foreach (array_slice(array_values($coinSpecific), 0, $limit - count($items)) as $n) {
                    $items[] = $n;
                }
            } catch (\Exception $e) {
                Log::warning('RSS coin filter failed', ['symbol' => $symbol]);
            }
        }

        // Sort by recency, deduplicate by title
        $seen  = [];
        $unique = [];
        foreach ($items as $item) {
            $key = substr($item['title'], 0, 60);
            if (!in_array($key, $seen)) {
                $seen[]   = $key;
                $unique[] = $item;
            }
        }
        usort($unique, fn($a, $b) => strtotime($b['published_at'] ?? '0') - strtotime($a['published_at'] ?? '0'));
        $result = array_slice($unique, 0, $limit);

        Cache::put($cacheKey, $result, now()->addMinutes(10));
        return $result;
    }

    // ── Sentiment analysis (enhanced) ─────────────────────────────────────
    private function analyzeTextSentiment(string $text): array {
        $bullishCount = 0;
        $bearishCount = 0;
        $panicCount   = 0;

        foreach (self::BULLISH_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) $bullishCount++;
        }
        foreach (self::BEARISH_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) $bearishCount++;
        }
        foreach (self::PANIC_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) $panicCount++;
        }

        // Panic overrides everything if strong enough
        if ($panicCount >= 2 || ($panicCount > 0 && $panicCount >= $bullishCount)) {
            return ['sentiment' => 'panic', 'score' => -0.8];
        }
        if ($bearishCount > $bullishCount) {
            $score = -0.4 - (0.05 * min($bearishCount, 10));
            return ['sentiment' => 'bearish', 'score' => round($score, 2)];
        }
        if ($bullishCount > $bearishCount) {
            $score = 0.4 + (0.05 * min($bullishCount, 10));
            return ['sentiment' => 'bullish', 'score' => round($score, 2)];
        }

        return ['sentiment' => 'neutral', 'score' => 0.0];
    }

    // ── Coin detection ────────────────────────────────────────────────────
    private function detectCoins(string $text): array {
        $detected = [];
        foreach (self::COIN_KEYWORDS as $symbol => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $detected[] = $symbol;
                    break;
                }
            }
        }
        return array_unique($detected) ?: ['BTC'];
    }

    private function isCoinMentioned(string $text, string $baseAsset): bool {
        $keywords = self::COIN_KEYWORDS[$baseAsset] ?? [strtolower($baseAsset)];
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) return true;
        }
        return false;
    }

    private function getCoinFullName(string $baseAsset): string {
        $names = [
            'BTC' => 'Bitcoin', 'ETH' => 'Ethereum', 'SOL' => 'Solana',
            'BNB' => 'Binance Coin', 'XRP' => 'Ripple', 'ADA' => 'Cardano',
            'DOGE' => 'Dogecoin', 'LINK' => 'Chainlink', 'AVAX' => 'Avalanche',
            'DOT' => 'Polkadot', 'MATIC' => 'Polygon', 'SHIB' => 'Shiba Inu',
            'LTC' => 'Litecoin', 'UNI' => 'Uniswap', 'ATOM' => 'Cosmos',
            'NEAR' => 'NEAR Protocol', 'ARB' => 'Arbitrum', 'OP' => 'Optimism',
            'INJ' => 'Injective', 'SUI' => 'Sui', 'APT' => 'Aptos',
        ];
        return $names[$baseAsset] ?? $baseAsset;
    }

    private function getMockNewsFallback(): array {
        return [
            [
                'source'          => 'cointelegraph',
                'title'           => 'Bitcoin Holds Steady Above $65k As Institutional Inflows Surge',
                'url'             => 'https://cointelegraph.com',
                'type'            => 'crypto',
                'sentiment'       => 'bullish',
                'sentiment_score' => 0.6,
                'coins_mentioned' => ['BTC'],
                'published_at'    => now()->toDateTimeString(),
            ],
            [
                'source'          => 'coindesk',
                'title'           => 'Ethereum Fees Drop To Historic Lows, Boosting Layer-2 Adoption',
                'url'             => 'https://coindesk.com',
                'type'            => 'crypto',
                'sentiment'       => 'bullish',
                'sentiment_score' => 0.5,
                'coins_mentioned' => ['ETH'],
                'published_at'    => now()->toDateTimeString(),
            ],
        ];
    }
}
