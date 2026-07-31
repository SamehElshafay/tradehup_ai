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

    public function analyze(string $symbol, string $exchange = 'binance', string $interval = '4h', int $limit = 500): array {
        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/analyze", [
                'symbol' => $symbol,
                'exchange' => $exchange,
                'interval' => $interval,
                'limit' => $limit
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

    public function isHealthy(): bool {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");
            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }
}
