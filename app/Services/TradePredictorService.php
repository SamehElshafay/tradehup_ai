<?php

namespace App\Services;

use App\Models\PaperTrade;
use App\Models\Recommendation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TradePredictorService
{
    /**
     * Build the training dataset from past closed trades that have hindsight data.
     * We limit this to the most recent 50-100 trades to fit inside the LLM context window.
     */
    public function getHistoricalTrainingData(int $limit = 50, string $filterCoin = null)
    {
        $query = PaperTrade::with(['coin', 'recommendation.analysis'])
            ->whereNotNull('hindsight_result')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at');

        if ($filterCoin) {
            // Priority ordering is complex in Eloquent without raw statements, 
            // so we just get general latest closed trades. The LLM will sort it out.
        }

        $trades = $query->limit($limit)->get();
        $dataset = [];

        foreach ($trades as $trade) {
            $rec = $trade->recommendation;
            if (!$rec) continue;
            
            $analysis = $rec->analysis;
            if (!$analysis) continue;

            $rawData = is_string($analysis->raw_data) ? json_decode($analysis->raw_data, true) : $analysis->raw_data;
            if (!is_array($rawData)) continue;

            // Extract dense metrics (ignore full klinedata arrays to save tokens)
            $metrics = [
                'rsi' => $rawData['classical']['rsi']['value'] ?? null,
                'ema_trend' => $rawData['classical']['moving_averages']['trend'] ?? null,
                'volatility' => $rawData['volatility'] ?? null,
                'smc_bias' => $rawData['smc']['market_structure']['trend'] ?? null,
                'order_blocks' => count($rawData['smc']['order_blocks'] ?? []),
                'fvgs' => count($rawData['smc']['fvgs'] ?? []),
            ];

            // Extract the actual outcome from Hindsight
            $hr = is_string($trade->hindsight_result) ? json_decode($trade->hindsight_result, true) : $trade->hindsight_result;
            
            $outcome = "Failed (Hit SL)";
            if ($hr['tp3_hit'] ?? false) $outcome = "Hit TP3 (Full Profit)";
            elseif ($hr['tp2_hit'] ?? false) $outcome = "Hit TP2";
            elseif ($hr['tp1_hit'] ?? false) $outcome = "Hit TP1";
            elseif (($hr['closed_by'] ?? '') === 'MANUAL' && $trade->pnl > 0) $outcome = "Manual Close (Profit)";

            $dataset[] = [
                'trade_id' => $trade->id,
                'coin' => $trade->coin->symbol ?? 'UNKNOWN',
                'action' => $trade->type,
                'strategy' => $rec->strategy,
                'confluences' => $rec->confluences,
                'risk_reward' => $this->parseRiskReward($rec->risk_reward),
                'technical_metrics' => $metrics,
                'hindsight_outcome' => $outcome,
                'pnl_percent_achieved' => $trade->pnl_percent,
            ];
        }

        return $dataset;
    }

    /**
     * Sends the historical dataset to the Python ML service to train the model locally.
     */
    public function trainLocalModel(): array
    {
        $dataset = $this->getHistoricalTrainingData(500); // Send up to 500 trades for training
        
        $url = rtrim(config('services.ta_engine.url', env('TA_SERVICE_URL', 'http://localhost:8001')), '/');
        
        $response = Http::timeout(120)->post("{$url}/ml/train", $dataset);
        
        if (!$response->successful()) {
            Log::error("ML Train API Error: " . $response->body());
            throw new \Exception("Failed to train ML model. Python service responded with an error.");
        }
        
        return $response->json();
    }

    /**
     * Trigger historical Binance data training on the Python service.
     * The Python service fetches OHLCV data, generates synthetic labeled trades,
     * and trains the ML model — all without needing any paper trade history.
     */
    public function trainOnHistoricalData(array $options = []): array
    {
        $url = rtrim(config('services.ta_engine.url', env('TA_SERVICE_URL', 'http://localhost:8001')), '/');
        
        $payload = [
            'coins'      => $options['coins']      ?? null,
            'timeframes' => $options['timeframes'] ?? null,
            'limit'      => $options['limit']      ?? 1000,
        ];

        // Filter out null values so Python uses its defaults
        $payload = array_filter($payload, fn($v) => $v !== null);

        $response = Http::timeout(600)->post("{$url}/ml/train-historical", $payload);

        if (!$response->successful()) {
            Log::error('ML Train Historical Error: ' . $response->body());
            throw new \Exception('Failed to train ML model on historical data. Python service error.');
        }

        return $response->json();
    }

    /**
     * Evaluate a new opportunity against the local trained ML model.
     */
    public function predictOutcome(Recommendation $opportunity)
    {
        $opportunity->load(['coin', 'analysis']);
        
        // Prepare new opportunity data
        $analysis = $opportunity->analysis;
        $rawData = is_string($analysis->raw_data) ? json_decode($analysis->raw_data, true) : $analysis->raw_data;
        $metrics = [];
        if (is_array($rawData)) {
            $metrics = [
                'rsi' => $rawData['classical']['rsi']['value'] ?? null,
                'ema_trend' => $rawData['classical']['moving_averages']['trend'] ?? null,
                'volatility' => $rawData['volatility'] ?? null,
                'smc_bias' => $rawData['smc']['market_structure']['trend'] ?? null,
                'order_blocks' => count($rawData['smc']['order_blocks'] ?? []),
                'fvgs' => count($rawData['smc']['fvgs'] ?? []),
            ];
        }

        $newSetup = [
            'coin' => $opportunity->coin->symbol ?? 'UNKNOWN',
            'timeframe' => $opportunity->timeframe ?? '15m',
            'action' => $opportunity->action,
            'strategy' => $opportunity->strategy,
            'confluences' => $opportunity->confluences,
            'risk_reward' => $this->parseRiskReward($opportunity->risk_reward),
            'entry_price' => $opportunity->entry_price,
            'sl' => $opportunity->sl,
            'technical_metrics' => $metrics,
        ];

        $url = rtrim(config('services.ta_engine.url', env('TA_SERVICE_URL', 'http://localhost:8001')), '/');
        
        $response = Http::timeout(30)->post("{$url}/ml/predict", $newSetup);
        
        if (!$response->successful()) {
            Log::error("ML Predict API Error: " . $response->body());
            throw new \Exception("Failed to predict ML model. Python service responded with an error.");
        }
        
        return $response->json();
    }

    /**
     * Parses the risk_reward string to a float value.
     */
    private function parseRiskReward($rr): float
    {
        if (is_numeric($rr)) {
            return (float) $rr;
        }
        if (is_string($rr) && preg_match('/(?:1:)?(\d+(?:\.\d+)?)/', $rr, $matches)) {
            return (float) $matches[1];
        }
        return 0.0;
    }
}
