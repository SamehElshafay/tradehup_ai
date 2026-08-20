<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OpportunityController extends Controller {
    public function index(Request $request): JsonResponse {
        $minConfidence = $request->get('min_confidence', 60);
        $action        = $request->get('action');
        $timeframe     = $request->get('timeframe');
        $perPage       = $request->get('per_page', 50); // increased default to show all

        $query = Recommendation::with(['coin', 'analysis'])
            // Show active AND recently-expired (within last 2h) so short-TF (5m/15m) trades
            // don't vanish from the list before the user gets a chance to see them
            ->where(function ($q) {
                $q->where('status', 'active')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'expired')
                         ->where('updated_at', '>=', now()->subHours(2));
                  });
            })
            ->where('action', '!=', 'WAIT')
            ->where('confidence', '>=', $minConfidence);

        if ($action)    $query->where('action', $action);
        if ($timeframe) $query->where('timeframe', $timeframe);

        $opportunities = $query->orderByDesc('created_at')->orderByDesc('confidence')->paginate($perPage);
        return response()->json($opportunities);
    }

    public function scan(Request $request): JsonResponse {
        \App\Jobs\ScanMarketOpportunitiesJob::dispatch();
        return response()->json([
            'message' => 'Market scan started in the background. You will be notified when opportunities are found.'
        ]);
    }

    /**
     * Lets the frontend show a persistent "scanning" indicator that's correct even if
     * the page was opened/refreshed after an automatic (scheduled) scan already started,
     * instead of relying only on live broadcast events from the currently open tab.
     */
    public function scanStatus(): JsonResponse {
        return response()->json([
            'scanning' => (bool) \Illuminate\Support\Facades\Cache::get('ai_scan_running', false),
        ]);
    }

    /**
     * Aggregate win-rate stats across all closed recommendations (hit_tp1/2/3 vs hit_sl),
     * the same way PaperTradingSession already tracks win_rate — so overall AI accuracy
     * can be judged from data, not from a single anecdotal outcome.
     */
    public function stats(Request $request): JsonResponse {
        $query = Recommendation::whereIn('status', ['hit_tp1', 'hit_tp2', 'hit_tp3', 'hit_sl']);
        if ($timeframe = $request->get('timeframe')) $query->where('timeframe', $timeframe);

        $counts = (clone $query)->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $wins   = ($counts['hit_tp1'] ?? 0) + ($counts['hit_tp2'] ?? 0) + ($counts['hit_tp3'] ?? 0);
        $losses = $counts['hit_sl'] ?? 0;
        $totalClosed = $wins + $losses;

        return response()->json([
            'total_closed'  => $totalClosed,
            'wins'          => $wins,
            'losses'        => $losses,
            'win_rate'      => $totalClosed > 0 ? round(($wins / $totalClosed) * 100, 2) : 0,
            'breakdown'     => [
                'hit_tp1' => $counts['hit_tp1'] ?? 0,
                'hit_tp2' => $counts['hit_tp2'] ?? 0,
                'hit_tp3' => $counts['hit_tp3'] ?? 0,
                'hit_sl'  => $counts['hit_sl'] ?? 0,
            ],
            'active_count'  => Recommendation::where('status', 'active')
                ->where('action', '!=', 'WAIT')
                ->when($timeframe, fn($q) => $q->where('timeframe', $timeframe))
                ->count(),
        ]);
    }
    
    public function trainModel(\App\Services\TradePredictorService $predictorService): JsonResponse {
        try {
            $result = $predictorService->trainLocalModel();
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function predict(Request $request, $id, \App\Services\TradePredictorService $predictorService): JsonResponse {
        $opportunity = Recommendation::findOrFail($id);
        
        try {
            $prediction = $predictorService->predictOutcome($opportunity);
            return response()->json([
                'success' => true,
                'prediction' => $prediction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Train the ML model using historical Binance OHLCV data.
     * This generates thousands of synthetic labeled trades for training.
     */
    public function trainHistorical(Request $request)
    {
        set_time_limit(0); // Allow training to run for as long as it needs
        
        try {
            $options = [
                'coins'      => $request->input('coins'),
                'timeframes' => $request->input('timeframes'),
                'limit'      => $request->input('limit', 1000),
            ];

            $predictor = new \App\Services\TradePredictorService();
            $result    = $predictor->trainOnHistoricalData($options);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Historical Train Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the current training status from Python service.
     */
    public function trainHistoricalStatus()
    {
        try {
            $url      = rtrim(config('services.ta_engine.url', env('TA_SERVICE_URL', 'http://localhost:8001')), '/');
            $response = \Illuminate\Support\Facades\Http::timeout(10)->get("{$url}/ml/train-historical/status");
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['status' => 'unknown', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch top Binance coins and evaluate general market trend/rebound for Spot traders.
     */
    public function marketHealth(Request $request): JsonResponse
    {
        $topCoins = \Illuminate\Support\Facades\Cache::remember('binance_top_50_tickers_health', 10, function () {
            try {
                $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                    ->timeout(12)
                    ->get('https://api.binance.com/api/v3/ticker/24hr');
                if ($response->successful()) {
                    $tickers = $response->json();
                    if (is_array($tickers)) {
                        $usdtPairs = [];
                        foreach ($tickers as $t) {
                            $symbol = $t['symbol'] ?? '';
                            if (str_ends_with($symbol, 'USDT') 
                                && !str_contains($symbol, 'UPUSDT') 
                                && !str_contains($symbol, 'DOWNUSDT')
                                && !empty($t['quoteVolume'])
                            ) {
                                $usdtPairs[] = [
                                    'symbol' => $symbol,
                                    'priceChangePercent' => $t['priceChangePercent'] ?? '0',
                                    'lastPrice' => $t['lastPrice'] ?? '0',
                                    'highPrice' => $t['highPrice'] ?? '0',
                                    'lowPrice' => $t['lowPrice'] ?? '0',
                                    'quoteVolume' => $t['quoteVolume'] ?? '0',
                                ];
                            }
                        }
                        // Sort by quoteVolume descending
                        usort($usdtPairs, function($a, $b) {
                            return (float)($b['quoteVolume'] ?? 0) <=> (float)($a['quoteVolume'] ?? 0);
                        });
                        return array_slice($usdtPairs, 0, 50);
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Market Health fetch failed: ' . $e->getMessage());
            }
            return null;
        });

        if (!$topCoins || !is_array($topCoins)) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch market data from Binance',
                'health' => [
                    'status' => 'unknown',
                    'status_label' => 'Unknown status',
                    'panic_index' => 50,
                    'decline_ratio' => 50,
                    'avg_change_24h' => 0,
                    'avg_rebound' => 0,
                    'warning_level' => 'info',
                    'warning_title' => 'بيانات السوق غير متوفرة',
                    'warning_title_en' => 'Market Data Unavailable',
                    'warning_desc_en' => 'Unable to fetch market data to determine overall trend status.',
                    'recommendation_level' => 4,
                    'recommendation_text' => 'انتظار ومراقبة 🕒',
                    'recommendation_text_en' => 'Wait & Watch 🕒',
                    'recommendation_color' => '#6b7280'
                ]
            ]);
        }

        $totalCount = count($topCoins);
        if ($totalCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No active USDT pairs found'
            ]);
        }

        $downCount = 0;
        $upCount = 0;
        $sumChange = 0;
        $sumRebound = 0;
        $reboundCount = 0;

        foreach ($topCoins as $coin) {
            $change = (float)($coin['priceChangePercent'] ?? 0);
            $last = (float)($coin['lastPrice'] ?? 0);
            $high = (float)($coin['highPrice'] ?? 0);
            $low = (float)($coin['lowPrice'] ?? 0);

            $sumChange += $change;

            if ($change < 0) {
                $downCount++;
                if ($low > 0) {
                    // Calculate rebound percentage from the 24h low
                    $rebound = (($last - $low) / $low) * 100;
                    $sumRebound += $rebound;
                    $reboundCount++;
                }
            } else {
                $upCount++;
            }
        }

        $avgChange24h = $sumChange / $totalCount;
        $declineRatio = ($downCount / $totalCount) * 100;
        $avgRebound = $reboundCount > 0 ? ($sumRebound / $reboundCount) : 0;

        // Calculate Panic Index (0-100) based on decline ratio and average change
        $changeFactor = max(-10, min(10, $avgChange24h)); // clamp between -10% and +10%
        $changePanic = (($changeFactor - 10) / -20) * 100; // Map [-10, 10] -> [100, 0]
        $panicIndex = ($declineRatio * 0.6) + ($changePanic * 0.4);
        $panicIndex = round(max(0, min(100, $panicIndex)));

        // Default: Stable/Mixed Spot environment
        $status = 'normal';
        $warningLevel = 'info';
        $warningTitleAr = 'سوق مستقر (تداول حذر)';
        $warningTitleEn = 'Stable Market (Trade Cautiously)';
        $warningDescAr = 'حالة السوق العام مستقرة نسبياً ومختلطة بين الصعود والهبوط. يمكنك الاستمرار في تداول صفقات السبوت الجيدة مع الالتزام بوقف خسارة محكم.';
        $warningDescEn = 'The overall market is relatively stable with mixed movements. Continue trading strong spot setups with strict stop-losses.';

        // Condition logic optimized for Spot trading safety
        if ($declineRatio >= 80 && $avgChange24h <= -3.5) {
            // Panic sell / severe dump - High risk of getting stuck in Spot
            $status = 'danger';
            $warningLevel = 'danger';
            $warningTitleAr = '🚨 انهيار عام وسوق هابط (خطر شديد على السبوت)';
            $warningTitleEn = '🚨 Market Crash (High Risk for Spot)';
            $warningDescAr = "تحذير: هبوط حاد وشبه جماعي في السوق بنسبة {$declineRatio}% ومتوسط تراجع " . round($avgChange24h, 2) . "%. ينصح بشدة بالابتعاد عن الشراء وتسييل صفقات السبوت لتجنب التعليق في الخسائر.";
            $warningDescEn = "Warning: Severe market drawdown! {$declineRatio}% of coins are down with an average change of " . round($avgChange24h, 2) . "%. Avoid opening Spot positions to prevent getting stuck.";
        } elseif ($declineRatio >= 60 && $avgChange24h <= -1.5) {
            // Moderate drop, check if we are starting to rebound!
            if ($avgRebound >= 1.5) {
                // Rebounding from bottom - Potential Spot entry bounce
                $status = 'rebound_up';
                $warningLevel = 'warning';
                $warningTitleAr = '🔄 ارتداد صعودي نشط بعد هبوط (فرصة شراء سبوت)';
                $warningTitleEn = '🔄 Market Rebounding (Spot Bounce Opportunity)';
                $warningDescAr = "رغم أن اتجاه الـ 24 ساعة سلبي، إلا أن السوق بدأ يرتد صعوداً من القاع بمتوسط ارتداد " . round($avgRebound, 2) . "%. قد تكون هناك فرص شراء ومضاربة سريعة (Spot Bounces) مع تفعيل وقف الخسارة.";
                $warningDescEn = "Although the 24h trend is negative, the market is rebounding from its lows by " . round($avgRebound, 2) . "% on average. Spot bounce opportunities may be viable.";
            } else {
                // Bearish pressure - Risk is rising
                $status = 'warning';
                $warningLevel = 'warning';
                $warningTitleAr = '⚠️ تراجع عام في السوق (ضغط بيعي)';
                $warningTitleEn = '⚠️ General Market Drawdown';
                $warningDescAr = "السوق يميل للهبوط بمتوسط " . round($avgChange24h, 2) . "% وهبوط {$declineRatio}% من العملات. يرجى توخي الحذر الشديد عند تجميع السبوت حالياً.";
                $warningDescEn = "The market is leaning downwards with an average change of " . round($avgChange24h, 2) . "%. Be cautious when accumulating Spot assets.";
            }
        } elseif ($declineRatio <= 30 && $avgChange24h >= 2.0) {
            // Strong bullish rally - Very safe for Spot
            $status = 'bullish';
            $warningLevel = 'success';
            $warningTitleAr = '🟢 انتعاش وصعود جماعي (آمن لتداول السبوت)';
            $warningTitleEn = '🟢 Market-wide Rally (Safe for Spot)';
            $warningDescAr = "السوق في حالة انتعاش ممتازة! متوسط الارتفاع العام بلغت نسبته +" . round($avgChange24h, 2) . "% وصعود أكثر من " . (100 - $declineRatio) . "% من العملات القيادية. ظروف مثالية ومطمنة لتداول السبوت.";
            $warningDescEn = "The market is in a strong rally! The average increase is +" . round($avgChange24h, 2) . "% with over " . (100 - $declineRatio) . "% of coins gaining. Ideal environment for Spot trading.";
        } else {
            // Neutral/mixed check for minor rebounds
            if ($avgChange24h < 0 && $avgRebound >= 1.2) {
                $status = 'minor_rebound';
                $warningLevel = 'info';
                $warningTitleAr = '🔄 ارتداد صعودي تدريجي قيد التشكّل';
                $warningTitleEn = '🔄 Gradual Rebound Forming';
                $warningDescAr = "السوق العام يظهر تراجعاً طفيفاً ولكنه يبدأ بالارتداد التدريجي من القاع بمتوسط " . round($avgRebound, 2) . "%. راقب إشارات الشراء المكتملة.";
                $warningDescEn = "The market is down slightly but showing gradual recovery from today's lows by " . round($avgRebound, 2) . "%. Watch active buy recommendations.";
            }
        }

        // 7-Level Spot Recommendation System based on market indicators
        // Evaluated from most dangerous (7) → safest (1) to avoid gaps
        $recLevel = 4;
        $recText  = 'انتظار ومراقبة 🕒';
        $recTextEn = 'Wait & Watch 🕒';
        $recColor = '#6b7280'; // Slate

        if ($avgChange24h <= -5.0 || ($declineRatio >= 85 && $panicIndex >= 75)) {
            // Level 7: Severe crash / heavy panic selling
            $recLevel = 7;
            $recText   = 'بيع جداً - خروج كامل فوراً 🚨';
            $recTextEn = 'Sell Heavily - Immediate Full Exit 🚨';
            $recColor  = '#ef4444'; // Red

        } elseif ($declineRatio >= 75 && $avgChange24h <= -2.5) {
            // Level 6: High-risk drop, minimal recovery
            $recLevel = 6;
            $recText   = 'بيع - خروج تدريجي ⚠️';
            $recTextEn = 'Sell - Gradual Exit ⚠️';
            $recColor  = '#f97316'; // Orange

        } elseif ($declineRatio >= 60 && $avgRebound < 1.3) {
            // Level 5: Red market, no meaningful rebound yet → avoid buying
            $recLevel = 5;
            $recText   = 'لا تشتري - تجنب الدخول 🛑';
            $recTextEn = 'Do Not Buy - Avoid Entering 🛑';
            $recColor  = '#eab308'; // Amber

        } elseif ($declineRatio >= 60 && $avgRebound >= 1.3) {
            // Level 3: Market down but solid rebound forming → cautious buy
            $recLevel = 3;
            $recText   = 'اشتري ولكن بحذر 🔍';
            $recTextEn = 'Buy with Caution 🔍';
            $recColor  = '#3b82f6'; // Blue

        } elseif ($declineRatio >= 45 && $avgChange24h < 0) {
            // Level 4: Mixed/flat with mild bearish tilt → wait
            $recLevel = 4;
            $recText   = 'انتظار ومراقبة 🕒';
            $recTextEn = 'Wait & Watch 🕒';
            $recColor  = '#6b7280'; // Slate

        } elseif ($declineRatio <= 30 && $avgChange24h >= 2.5) {
            // Level 1: Super bullish rally → strong buy
            $recLevel = 1;
            $recText   = 'شراء قوي - السوق آمن جداً 🔥';
            $recTextEn = 'Strong Buy - Market Very Safe 🔥';
            $recColor  = '#059669'; // Emerald

        } elseif ($declineRatio <= 45 && $avgChange24h >= 0.5) {
            // Level 2: Healthy green market → buy
            $recLevel = 2;
            $recText   = 'اشتري - السوق آمن ✅';
            $recTextEn = 'Buy - Market Safe ✅';
            $recColor  = '#22c55e'; // Green

        } else {
            // Level 4 fallback: anything else → wait and observe
            $recLevel = 4;
            $recText   = 'انتظار ومراقبة 🕒';
            $recTextEn = 'Wait & Watch 🕒';
            $recColor  = '#6b7280';
        }

        return response()->json([
            'success' => true,
            'health' => [
                'status' => $status,
                'panic_index' => $panicIndex,
                'decline_ratio' => round($declineRatio, 1),
                'avg_change_24h' => round($avgChange24h, 2),
                'avg_rebound' => round($avgRebound, 2),
                'warning_level' => $warningLevel,
                'warning_title' => $warningTitleAr,
                'warning_title_en' => $warningTitleEn,
                'warning_desc' => $warningDescAr,
                'warning_desc_en' => $warningDescEn,
                'recommendation_level' => $recLevel,
                'recommendation_text' => $recText,
                'recommendation_text_en' => $recTextEn,
                'recommendation_color' => $recColor,
                'top_coins_scanned' => $totalCount
            ]
        ]);
    }
}
