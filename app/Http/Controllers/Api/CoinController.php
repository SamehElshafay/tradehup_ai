<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coin;
use App\Models\Favorite;
use App\Models\Follow;
use App\Services\PythonTAService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CoinController extends Controller
{
    public function __construct(private PythonTAService $taService) {}

    public function index(Request $request): JsonResponse
    {
        // Sync USDT pairs across multiple exchanges (Binance, Bybit, MEXC) once a day
        if (!\Illuminate\Support\Facades\Cache::has('market_pairs_synced')) {
            $this->syncMarketPairs();
            \Illuminate\Support\Facades\Cache::put('market_pairs_synced', true, 86400); // 24h
        }

        $query = Coin::active();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('base_asset', 'like', "%{$search}%");
            });
        }

        $coins = $query->orderByDesc('volume_24h')
                       ->paginate($request->get('per_page', 20));

        // Add user-specific data if authenticated
        if ($user = $request->user()) {
            $userFavorites = $user->favoritedCoins()->pluck('coins.id')->toArray();
            $userFollows   = $user->followedCoins()->pluck('coins.id')->toArray();
            $coins->getCollection()->transform(function ($coin) use ($userFavorites, $userFollows) {
                $coin->is_favorited = in_array($coin->id, $userFavorites);
                $coin->is_followed  = in_array($coin->id, $userFollows);
                return $coin;
            });
        }

        return response()->json($coins);
    }

    public function top(Request $request): JsonResponse
    {
        $coins = Cache::remember('top_coins', 60, function () {
            return Coin::active()->orderByDesc('volume_24h')->limit(50)->get();
        });

        return response()->json(['coins' => $coins]);
    }

    public function show(Request $request, string $symbol): JsonResponse
    {
        $coin = Coin::where('symbol', strtoupper($symbol))->firstOrFail();

        if ($user = $request->user()) {
            $coin->is_favorited = $coin->isFavoritedByUser($user->id);
            $coin->is_followed  = $coin->isFollowedByUser($user->id);
        }

        // Get latest recommendation
        $coin->latest_recommendation = $coin->recommendations()
            ->where('status', 'active')
            ->latest()
            ->first();

        return response()->json(['coin' => $coin]);
    }

    public function ohlcv(Request $request, string $symbol): JsonResponse
    {
        $coin = Coin::where('symbol', strtoupper($symbol))->firstOrFail();
        $interval = $request->get('interval', '4h');
        $limit    = $request->get('limit', 200);

        $exchange = $coin->exchange ?? 'binance';
        $data = $this->taService->getOHLCV(strtoupper($symbol), $exchange, $interval, (int) $limit);
        return response()->json($data);
    }

    public function price(string $symbol): JsonResponse
    {
        $coin = Coin::where('symbol', strtoupper($symbol))->firstOrFail();
        $exchange = $coin->exchange ?? 'binance';
        $price = $this->taService->getPrice(strtoupper($symbol), $exchange);
        return response()->json(['symbol' => strtoupper($symbol), 'price' => $price]);
    }

    public function favorite(Request $request, int $id): JsonResponse
    {
        $coin = Coin::findOrFail($id);
        $user = $request->user();

        Favorite::firstOrCreate(['user_id' => $user->id, 'coin_id' => $coin->id]);
        return response()->json(['message' => "Added {$coin->symbol} to favorites"]);
    }

    public function unfavorite(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        Favorite::where('user_id', $user->id)->where('coin_id', $id)->delete();
        return response()->json(['message' => 'Removed from favorites']);
    }

    public function follow(Request $request, int $id): JsonResponse
    {
        $coin   = Coin::findOrFail($id);
        $user   = $request->user();
        $notify = $request->boolean('notify', true);

        Follow::updateOrCreate(
            ['user_id' => $user->id, 'coin_id' => $coin->id],
            ['notify' => $notify]
        );

        return response()->json(['message' => "Following {$coin->symbol}"]);
    }

    public function unfollow(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        Follow::where('user_id', $user->id)->where('coin_id', $id)->delete();
        return response()->json(['message' => 'Unfollowed coin']);
    }

    public function favorites(Request $request): JsonResponse
    {
        $coins = $request->user()->favoritedCoins()->get();
        return response()->json(['coins' => $coins]);
    }

    public function follows(Request $request): JsonResponse
    {
        $coins = $request->user()->followedCoins()->withPivot('notify')->get();
        return response()->json(['coins' => $coins]);
    }

    private function syncMarketPairs(): void
    {
        ini_set('memory_limit', '512M');
        
        $this->syncBinance();
        $this->syncBybit();
        $this->syncMexc();
    }

    private function syncBinance(): void
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()->get('https://api.binance.com/api/v3/exchangeInfo');
            if ($response->successful()) {
                $symbols = $response->json('symbols');
                foreach ($symbols as $pair) {
                    if ($pair['quoteAsset'] === 'USDT' && $pair['status'] === 'TRADING') {
                        Coin::firstOrCreate(
                            ['symbol' => $pair['symbol']],
                            [
                                'name' => $pair['baseAsset'],
                                'base_asset' => $pair['baseAsset'],
                                'quote_asset' => 'USDT',
                                'exchange' => 'binance',
                                'current_price' => 0,
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {}
    }

    private function syncBybit(): void
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()->get('https://api.bybit.com/v5/market/instruments-info?category=spot');
            if ($response->successful()) {
                $symbols = $response->json('result.list');
                foreach ($symbols as $pair) {
                    if ($pair['quoteCoin'] === 'USDT' && $pair['status'] === 'Trading') {
                        Coin::firstOrCreate(
                            ['symbol' => $pair['symbol']],
                            [
                                'name' => $pair['baseCoin'],
                                'base_asset' => $pair['baseCoin'],
                                'quote_asset' => 'USDT',
                                'exchange' => 'bybit',
                                'current_price' => 0,
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {}
    }

    private function syncMexc(): void
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)->withoutVerifying()->get('https://api.mexc.com/api/v3/exchangeInfo');
            if ($response->successful()) {
                $symbols = $response->json('symbols');
                foreach ($symbols as $pair) {
                    if ($pair['quoteAsset'] === 'USDT' && $pair['status'] === 'ENABLED') {
                        Coin::firstOrCreate(
                            ['symbol' => $pair['symbol']],
                            [
                                'name' => $pair['baseAsset'],
                                'base_asset' => $pair['baseAsset'],
                                'quote_asset' => 'USDT',
                                'exchange' => 'mexc',
                                'current_price' => 0,
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {}
    }
}
