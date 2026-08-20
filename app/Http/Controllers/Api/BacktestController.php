<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PythonTAService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Backs the "Backtest Lab" tab — lets a user pick a coin/timeframe and replay
 * historical candles through the real deterministic analysis engine, seeing
 * every prediction plotted against what price actually did. Read-only and
 * free (Python-only, no AI calls) — see python-ta-service/services/backtest.py.
 */
class BacktestController extends Controller
{
    public function __construct(private PythonTAService $taService) {}

    public function chart(Request $request, string $symbol): JsonResponse
    {
        $validated = $request->validate([
            'exchange' => 'sometimes|string|in:binance,bybit,mexc',
            'interval' => 'sometimes|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,1w',
            'limit'    => 'sometimes|integer|min:50|max:1000',
            'step'     => 'sometimes|integer|min:1|max:20',
            'min_confidence' => 'sometimes|integer|min:0|max:100',
        ]);

        try {
            $result = $this->taService->runChartBacktest(
                strtoupper($symbol),
                $validated['exchange'] ?? 'binance',
                $validated['interval'] ?? '15m',
                (int) ($validated['limit'] ?? 500),
                (int) ($validated['step'] ?? 1),
                (int) ($validated['min_confidence'] ?? 0),
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
