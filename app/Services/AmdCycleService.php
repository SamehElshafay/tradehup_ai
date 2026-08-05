<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * AMD Cycle Service (Accumulation – Manipulation – Distribution)
 * ─────────────────────────────────────────────────────────────────────────────
 * Detects the ICT-style AMD cycle for the current UTC day using low-timeframe
 * (5m) candles:
 *
 * 1. Accumulation — price range (High/Low) during the Asian session, measured
 *    from 00:00 UTC up to the London open (07:00 UTC) so the range is locked
 *    in *before* any manipulation candle can be checked against it. Valid only
 *    if the range is narrow (< 9× ATR, data-derived — see
 *    ACCUMULATION_MAX_ATR_MULTIPLE) — a wide range means no real accumulation
 *    happened.
 * 2. Manipulation (Judas Swing) — a break of the accumulation high/low during
 *    the London (07:00–09:00 UTC) or NY (12:00–13:00 UTC) open, followed by
 *    price closing back inside the range within a configurable number of
 *    candles (default 3). This distinguishes a stop-hunt from a genuine
 *    breakout.
 * 3. Distribution direction — swept_high → BEARISH, swept_low → BULLISH.
 *
 * This layer is purely additive: if no valid accumulation zone or no
 * manipulation is found, `status` reflects that and the rest of the system
 * (prompt, pre-trade filter, report) proceeds exactly as it would without it.
 */
class AmdCycleService
{
    private const ACCUMULATION_START_HOUR = 0;

    // NOTE: the Asian session is broadly labelled 00:00–09:00 UTC, but the accumulation
    // range must be locked in BEFORE the earliest manipulation window (London open,
    // 07:00 UTC) — otherwise the "sweep" candles at London open would themselves get
    // folded into the accumulation high/low, making a real sweep impossible to detect
    // against its own inflated range. So the accumulation window is capped at 07:00 UTC.
    private const ACCUMULATION_END_HOUR   = 7;

    // [start_hour, end_hour, session_label]
    private const MANIPULATION_WINDOWS = [
        [7,  9,  'London'],
        [12, 13, 'New York'],
    ];

    // A 7-hour Asian-session range compared against a ~70-minute ATR (14×5m
    // candles) was almost mathematically guaranteed to fail at the old <1x
    // threshold. A real-data check (services/amd_validator.py, 192 symbol-days
    // across 8 coins) found the observed ratio NEVER dropped below 6.4x, with
    // a median of ~11x — the old threshold made "valid accumulation" fire zero
    // times in three weeks. 9x (~the real 25th percentile) still selects the
    // relatively tighter quarter of days instead of an unreachable bar.
    private const ACCUMULATION_MAX_ATR_MULTIPLE = 9;

    private const DEFAULT_RETURN_WINDOW_CANDLES = 3;
    private const CANDLE_INTERVAL   = '5m';
    private const CANDLE_LOOKBACK   = 300; // ~25h of 5m candles — comfortably covers the current UTC day

    public function __construct(private PythonTAService $taService) {}

    /**
     * @param  string $symbol   e.g. BTCUSDT
     * @param  string $exchange e.g. binance
     * @param  float  $atr      Reference ATR (from the primary analysis timeframe) used
     *                          to judge whether the Asian-session range counts as a real
     *                          accumulation zone.
     * @param  array  $settings ai_settings.json — reads 'amd_manipulation_window' (default 3)
     * @return array  See buildResult() for the full shape.
     */
    public function analyze(string $symbol, string $exchange, float $atr, array $settings = []): array
    {
        $returnWindow = (int) ($settings['amd_manipulation_window'] ?? self::DEFAULT_RETURN_WINDOW_CANDLES);
        $returnWindow = max(1, $returnWindow);

        try {
            $ohlcv = $this->taService->getOHLCV($symbol, $exchange, self::CANDLE_INTERVAL, self::CANDLE_LOOKBACK);
            $candles = $ohlcv['candles'] ?? [];
        } catch (\Exception $e) {
            Log::warning('AmdCycleService: failed to fetch candles', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return $this->noData('Candle data unavailable.');
        }

        if (empty($candles)) {
            return $this->noData('No candle data returned.');
        }

        $todayCandles = $this->filterToToday($candles);
        if (empty($todayCandles)) {
            return $this->noData('No candles found for the current UTC day yet.');
        }

        // ── 1. Accumulation ────────────────────────────────────────────────
        $asianCandles = $this->filterByHourRange($todayCandles, self::ACCUMULATION_START_HOUR, self::ACCUMULATION_END_HOUR);
        if (empty($asianCandles)) {
            return $this->noData('Asian session has not started yet today.');
        }

        $accHigh = max(array_column($asianCandles, 'high'));
        $accLow  = min(array_column($asianCandles, 'low'));
        $range   = $accHigh - $accLow;
        $isValid = $atr > 0 ? $range < ($atr * self::ACCUMULATION_MAX_ATR_MULTIPLE) : false;

        $accumulation = [
            'high'         => round($accHigh, 8),
            'low'          => round($accLow, 8),
            'range'        => round($range, 8),
            'range_vs_atr' => $atr > 0 ? round($range / $atr, 2) : null,
            'valid'        => $isValid,
            'window'       => '00:00–07:00 UTC',
            'candle_count' => count($asianCandles),
        ];

        if (!$isValid) {
            return $this->buildResult(
                status: 'invalid_accumulation',
                accumulation: $accumulation,
                summary: "Asian range (\${$accumulation['low']}–\${$accumulation['high']}) is too wide relative to ATR "
                       . "({$accumulation['range_vs_atr']}× ATR) — no real accumulation zone formed today."
            );
        }

        // ── 2. Manipulation (Judas Swing) ─────────────────────────────────
        $manipulation = $this->detectManipulation($todayCandles, $accHigh, $accLow, $returnWindow);

        if (!$manipulation) {
            return $this->buildResult(
                status: 'no_manipulation',
                accumulation: $accumulation,
                summary: "Valid accumulation zone (\${$accumulation['low']}–\${$accumulation['high']}), "
                       . "but no manipulation (liquidity sweep) detected yet during London/NY open."
            );
        }

        // ── 3. Distribution direction ──────────────────────────────────────
        $expectedDirection = $manipulation['direction'] === 'swept_high' ? 'bearish' : 'bullish';

        return $this->buildResult(
            status: 'ok',
            accumulation: $accumulation,
            manipulation: $manipulation,
            expectedDirection: $expectedDirection,
            summary: "Accumulation \${$accumulation['low']}–\${$accumulation['high']} (00:00–07:00 UTC) → "
                   . strtoupper($manipulation['direction']) . " sweep at {$manipulation['time']} ({$manipulation['session']} open) → "
                   . "expecting " . strtoupper($expectedDirection) . " distribution."
        );
    }

    /**
     * Scan the London/NY open windows chronologically for a candle that breaks the
     * accumulation range (wick or close) and then closes back inside it within
     * $returnWindow candles — the signature of a Judas Swing / stop-hunt rather
     * than a genuine breakout.
     */
    private function detectManipulation(array $todayCandles, float $accHigh, float $accLow, int $returnWindow): ?array
    {
        foreach (self::MANIPULATION_WINDOWS as [$startHour, $endHour, $sessionLabel]) {
            $windowCandles = $this->filterByHourRange($todayCandles, $startHour, $endHour);
            if (empty($windowCandles)) continue;

            // Index within the full today list so we can look at the candles that follow the sweep
            $indexBySignature = [];
            foreach ($todayCandles as $i => $c) {
                $indexBySignature[$c['time']] = $i;
            }

            foreach ($windowCandles as $sweepCandle) {
                $sweptHigh = $sweepCandle['high'] > $accHigh;
                $sweptLow  = $sweepCandle['low']  < $accLow;

                if (!$sweptHigh && !$sweptLow) continue;

                $sweepIndex = $indexBySignature[$sweepCandle['time']] ?? null;
                if ($sweepIndex === null) continue;

                // Look at the next $returnWindow candles for a close back inside the range
                for ($j = $sweepIndex + 1; $j <= $sweepIndex + $returnWindow && $j < count($todayCandles); $j++) {
                    $close = $todayCandles[$j]['close'];
                    if ($close <= $accHigh && $close >= $accLow) {
                        return [
                            'direction'      => $sweptHigh ? 'swept_high' : 'swept_low',
                            'sweep_price'    => $sweptHigh ? $sweepCandle['high'] : $sweepCandle['low'],
                            'time'           => gmdate('H:i', (int) $sweepCandle['time']) . ' UTC',
                            'session'        => $sessionLabel,
                            'candles_to_return' => $j - $sweepIndex,
                        ];
                    }
                }
                // Broke the range but never returned within the window — treat as a
                // genuine breakout, not manipulation. Keep scanning for other sweeps.
            }
        }

        return null;
    }

    private function filterToToday(array $candles): array
    {
        $todayDate = gmdate('Y-m-d');
        return array_values(array_filter($candles, fn($c) => gmdate('Y-m-d', (int) ($c['time'] ?? 0)) === $todayDate));
    }

    private function filterByHourRange(array $candles, int $startHour, int $endHour): array
    {
        return array_values(array_filter($candles, function ($c) use ($startHour, $endHour) {
            $hour = (int) gmdate('G', (int) ($c['time'] ?? 0));
            return $hour >= $startHour && $hour < $endHour;
        }));
    }

    private function noData(string $reason): array
    {
        return $this->buildResult(status: 'no_data', summary: "No clear AMD cycle detected — {$reason}");
    }

    private function buildResult(
        string  $status,
        ?array  $accumulation = null,
        ?array  $manipulation = null,
        ?string $expectedDirection = null,
        string  $summary = 'No clear AMD cycle detected today.'
    ): array {
        return [
            'status'                 => $status,
            'accumulation'           => $accumulation,
            'manipulation_detected'  => $manipulation !== null,
            'manipulation'           => $manipulation,
            'expected_direction'     => $expectedDirection,
            'summary'                => $summary,
        ];
    }
}
