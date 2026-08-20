<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PythonTAService {
    private string $baseUrl;

    public function __construct() {
        $this->baseUrl = rtrim(config('services.ta_engine.url', env('TA_SERVICE_URL', 'http://localhost:8001')), '/');
    }

    public function analyze(string $symbol, string $exchange = 'binance', string $interval = '4h', int $limit = 500, bool $includeHarmonic = true, bool $includeClassical = true, bool $includeCandles = true, bool $includeOrderBook = false, bool $includeCvd = false, bool $includeTakerPressure = false): array {
        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/analyze", [
                'symbol'                => $symbol,
                'exchange'              => $exchange,
                'interval'              => $interval,
                'limit'                 => $limit,
                'include_harmonic'      => $includeHarmonic,
                'include_classical'     => $includeClassical,
                'include_candles'       => $includeCandles,
                'include_order_book'    => $includeOrderBook,
                'include_cvd'           => $includeCvd,
                'include_taker_pressure'=> $includeTakerPressure,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('TA Service analyze failed', [
                'symbol' => $symbol, 'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new Exception('TA Service returned error: ' . $response->body());
        } catch (Exception $e) {
            Log::error('TA Service connection error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getOHLCV(string $symbol, string $exchange = 'binance', string $interval = '4h', int $limit = 200): array {
        try {
            $response = Http::timeout(30)->get("{$this->baseUrl}/ohlcv", [
                'symbol' => $symbol, 'exchange' => $exchange, 'interval' => $interval, 'limit' => $limit
            ]);
            return $response->successful() ? $response->json() : [];
        } catch (Exception $e) {
            Log::error('TA Service OHLCV error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getTopCoins(int $limit = 50): array {
        try {
            $response = Http::timeout(15)->get("{$this->baseUrl}/top-coins", ['limit' => $limit]);
            return $response->successful() ? ($response->json()['coins'] ?? []) : [];
        } catch (Exception $e) {
            Log::error('TA Service top coins error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getPrice(string $symbol, string $exchange = 'binance'): ?float {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/price/{$symbol}", ['exchange' => $exchange]);
            return $response->successful() ? ($response->json()['price'] ?? null) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Walk-forward backtest for the "Backtest Lab" tab: replays historical
     * candles through the real deterministic analysis engine and returns
     * every prediction (bias/confidence/TP/SL/outcome) plus the candle series
     * for the frontend to plot against the actual chart. Can take a while for
     * large limits (analyzing hundreds of candles), so a generous timeout.
     */
    public function runChartBacktest(string $symbol, string $exchange = 'binance', string $interval = '15m', int $limit = 500, int $step = 1, int $minConfidence = 0): array {
        $response = Http::timeout(600)->get("{$this->baseUrl}/backtest-chart", [
            'symbol' => $symbol, 'exchange' => $exchange, 'interval' => $interval, 'limit' => $limit, 'step' => $step, 'min_confidence' => $minConfidence,
        ]);

        if (!$response->successful()) {
            Log::error('TA Service backtest-chart failed', ['symbol' => $symbol, 'status' => $response->status(), 'body' => $response->body()]);
            throw new Exception('Backtest failed: ' . ($response->json()['detail'] ?? $response->body()));
        }

        return $response->json();
    }

    public function isHealthy(): bool {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");
            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }
}
