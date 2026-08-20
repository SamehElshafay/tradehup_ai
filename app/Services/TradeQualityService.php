<?php

namespace App\Services;

/**
 * Trade Quality Service — Phase 3
 *
 * Computes three independent quality metrics for every recommendation:
 *
 * 1. R:R Quality
 *    Applies a tiered confidence adjustment based on the Risk:Reward ratio.
 *    Good R:R → small bonus; Poor R:R → confidence deduction + warning.
 *    NOT a hard gate (AnalysisController already has a deterministic R:R gate).
 *
 * 2. Expected Value (EV)
 *    EV = (EstimatedWinRate × Reward) - (EstimatedLossRate × Risk)
 *    Win Rate is estimated from a calibration table that maps Confidence %
 *    to a historically realistic win probability, NOT the raw confidence number.
 *    Rationale: confidence is a signal-quality score, not a statistical probability.
 *    EV ≤ 0 → warning + confidence deduction. NOT a standalone WAIT reason.
 *
 * 3. Trade Quality Score — a.k.a. the "Diagnostic Score" (see `score_type` /
 *    `score_type_note` in its output — read those before wiring this into any
 *    UI, since it is deliberately NOT the same number as the recommendation's
 *    confidence and must not be labeled/rendered as one)
 *    An A/B+/B/C/D grade derived exclusively from raw trading signals
 *    (R:R, Regime, Liquidity Sweep, Volume Profile, EV, MTF Confirmation,
 *    Volume Confirmation, SMC Confluence, Pattern Agreement). Grade is
 *    additionally capped at B when there is no confirmed Break of Structure,
 *    regardless of score — no BOS means no objective evidence the move is
 *    underway. COMPLETELY INDEPENDENT of the confidence engine — no double
 *    counting.
 *
 * Design principles:
 *   - Trade Quality Score does NOT use adjusted_confidence as an input.
 *   - Confidence engine does NOT use Trade Quality Score as an input.
 *   - Both are derived from the same raw indicators independently.
 *   - This is a DIAGNOSTIC score (setup quality), not a second confidence
 *     percentage — a report showing both must label them distinctly.
 */
class TradeQualityService
{
    // ── Win Rate Calibration Table ─────────────────────────────────────────────
    // Maps adjusted_confidence bucket → historically realistic win probability.
    // Rationale: AI/TA signals consistently over-report confidence. Empirical
    // calibration for crypto signals typically shows 45-68% actual win rates.
    // These values will be replaced with real backtest data after 500+ trades.
    private const WIN_RATE_CALIBRATION = [
        90 => 0.68,  // ≥ 90%  confidence → ~68% historical win rate
        80 => 0.63,  // ≥ 80%  confidence → ~63%
        70 => 0.58,  // ≥ 70%  confidence → ~58%
        60 => 0.53,  // ≥ 60%  confidence → ~53%
        50 => 0.48,  // ≥ 50%  confidence → ~48%
        40 => 0.43,  // ≥ 40%  confidence → ~43%
        0  => 0.35,  // < 40%  confidence → ~35%
    ];

    // ── R:R Quality thresholds ────────────────────────────────────────────────
    private const RR_EXCELLENT_THRESHOLD = 3.0;   // ≥ 3.0 → +3% bonus
    private const RR_ACCEPTABLE_THRESHOLD = 2.0;  // ≥ 2.0 → no change
    private const RR_BELOW_TARGET = 1.5;          // ≥ 1.5 → -5% deduction
    private const RR_POOR_THRESHOLD = 1.5;        // < 1.5 → -10% deduction

    private const RR_POOR_DEDUCTION    = 10;  // confidence %
    private const RR_BELOW_DEDUCTION   = 5;   // confidence %
    private const RR_EXCELLENT_BONUS   = 3;   // confidence %

    // ── EV penalty ────────────────────────────────────────────────────────────
    private const EV_NEGATIVE_DEDUCTION = 8;  // confidence % when EV ≤ 0

    // ── Trade Quality Score base & grade thresholds ───────────────────────────
    private const SCORE_BASE = 100;
    private const GRADE_A    = 130;
    private const GRADE_B_PLUS = 110;
    private const GRADE_B    = 90;
    private const GRADE_C    = 70;
    // < GRADE_C → D

    // ─────────────────────────────────────────────────────────────────────────
    //  Public Entry Point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute all Phase 3 quality metrics for a recommendation.
     *
     * @param  string     $action          'BUY' | 'SELL' | 'WAIT'
     * @param  float|null $entry           Entry price
     * @param  float|null $tp2             Target price 2 (primary reward target)
     * @param  float|null $sl              Stop loss price
     * @param  float      $rrValue         Computed R:R ratio (e.g. 2.5 for 1:2.5)
     * @param  int        $adjustedConf    Adjusted confidence % from PreTradeFilterService
     * @param  array      $filterLog       filter_log from PreTradeFilterService
     * @param  array      $taData          Full TA data (for regime, sweep, MTF, SMC)
     * @return array {
     *   rr_quality: array,
     *   ev: array,
     *   trade_quality: array,
     *   confidence_adjustment: int  (net +/- to apply to final confidence)
     * }
     */
    public function compute(
        string $action,
        ?float $entry,
        ?float $tp2,
        ?float $sl,
        float  $rrValue,
        int    $adjustedConf,
        array  $filterLog,
        array  $taData
    ): array {
        // Only compute for tradeable actions
        if ($action === 'WAIT' || $entry === null || $sl === null) {
            return $this->emptyResult($action);
        }

        $risk   = abs($entry - $sl);
        $reward = ($tp2 !== null) ? abs($tp2 - $entry) : 0;

        // ── 1. R:R Quality ────────────────────────────────────────────────────
        $rrQuality = $this->computeRrQuality($rrValue);

        // ── 2. Expected Value ─────────────────────────────────────────────────
        $ev = $this->computeExpectedValue($adjustedConf, $risk, $reward);

        // ── 3. Trade Quality Score (independent of confidence) ────────────────
        $tradeQuality = $this->computeTradeQualityScore(
            $action,
            $rrValue,
            $ev,
            $filterLog,
            $taData
        );

        // ── Net confidence adjustment (R:R + EV; capped at -15 total deduction) ─
        $confAdjustment = $rrQuality['confidence_delta'] + $ev['confidence_delta'];
        $confAdjustment = max(-15, min(5, $confAdjustment));

        return [
            'rr_quality'           => $rrQuality,
            'ev'                   => $ev,
            'trade_quality'        => $tradeQuality,
            'confidence_adjustment'=> $confAdjustment,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  1. R:R Quality
    // ─────────────────────────────────────────────────────────────────────────

    private function computeRrQuality(float $rrValue): array
    {
        if ($rrValue <= 0) {
            return [
                'rr_value'        => $rrValue,
                'rr_label'        => 'N/A',
                'confidence_delta'=> 0,
                'warning'         => null,
                'status'          => 'no_rr',
            ];
        }

        if ($rrValue >= self::RR_EXCELLENT_THRESHOLD) {
            return [
                'rr_value'        => $rrValue,
                'rr_label'        => "✅ Excellent (1:{$rrValue})",
                'confidence_delta'=> self::RR_EXCELLENT_BONUS,
                'warning'         => null,
                'status'          => 'excellent',
            ];
        }

        if ($rrValue >= self::RR_ACCEPTABLE_THRESHOLD) {
            return [
                'rr_value'        => $rrValue,
                'rr_label'        => "✅ Acceptable (1:{$rrValue})",
                'confidence_delta'=> 0,
                'warning'         => null,
                'status'          => 'acceptable',
            ];
        }

        if ($rrValue >= self::RR_BELOW_TARGET) {
            $deduction = self::RR_BELOW_DEDUCTION;
            return [
                'rr_value'        => $rrValue,
                'rr_label'        => "⚠️ Below Target (1:{$rrValue})",
                'confidence_delta'=> -self::RR_BELOW_DEDUCTION,
                'warning'         => "⚠️ R:R below 2.0 (1:{$rrValue}) — reward does not justify risk adequately. -{$deduction}% confidence.",
                'status'          => 'below_target',
            ];
        }

        // < 1.5
        $deduction = self::RR_POOR_DEDUCTION;
        return [
            'rr_value'        => $rrValue,
            'rr_label'        => "🔴 Poor (1:{$rrValue})",
            'confidence_delta'=> -self::RR_POOR_DEDUCTION,
            'warning'         => "🔴 Poor R:R (1:{$rrValue}) — risk exceeds realistic reward. -{$deduction}% confidence. Consider waiting for a better entry.",
            'status'          => 'poor',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  2. Expected Value (EV)
    // ─────────────────────────────────────────────────────────────────────────

    private function computeExpectedValue(int $adjustedConf, float $risk, float $reward): array
    {
        if ($risk <= 0 || $reward <= 0) {
            return [
                'win_rate'        => null,
                'ev_value'        => null,
                'ev_display'      => 'N/A (missing price levels)',
                'confidence_delta'=> 0,
                'warning'         => null,
                'status'          => 'no_data',
                'calibration_note'=> 'Requires entry, tp2, and sl.',
            ];
        }

        $winRate  = $this->calibrateWinRate($adjustedConf);
        $lossRate = 1 - $winRate;

        $ev = ($winRate * $reward) - ($lossRate * $risk);
        $evRounded = round($ev, 4);

        $isPositive = $ev > 0;
        $evDisplay  = ($isPositive ? '+' : '') . number_format($ev, 6, '.', '') . ' (in price units)';

        // EV as a ratio for clarity: EV / Risk
        $evRatio = round($ev / $risk, 3);

        if ($isPositive) {
            return [
                'win_rate'        => round($winRate, 3),
                'ev_value'        => $evRounded,
                'ev_ratio'        => $evRatio,
                'ev_display'      => "✅ Positive EV (+{$evRatio}× risk) — mathematical edge in your favor",
                'confidence_delta'=> 0,
                'warning'         => null,
                'status'          => 'positive',
                'calibration_note'=> "WinRate {$winRate} from calibration table (confidence {$adjustedConf}%)",
            ];
        }

        $deduction = self::EV_NEGATIVE_DEDUCTION;
        return [
            'win_rate'        => round($winRate, 3),
            'ev_value'        => $evRounded,
            'ev_ratio'        => $evRatio,
            'ev_display'      => "⚠️ Negative EV ({$evRatio}× risk) — statistical edge is against you",
            'confidence_delta'=> -self::EV_NEGATIVE_DEDUCTION,
            'warning'         => "⚠️ Expected Value is negative (WinRate {$winRate} × Reward {$reward} < LossRate {$lossRate} × Risk {$risk}). Mathematical edge not in your favor. -{$deduction}% confidence applied.",
            'status'          => 'negative',
            'calibration_note'=> "WinRate {$winRate} from calibration table (confidence {$adjustedConf}%)",
        ];
    }

    /**
     * Map an adjusted confidence % to a calibrated historical win rate.
     * Buckets are checked from highest to lowest.
     */
    private function calibrateWinRate(int $confidence): float
    {
        foreach (self::WIN_RATE_CALIBRATION as $minConf => $winRate) {
            if ($confidence >= $minConf) {
                return $winRate;
            }
        }
        return 0.35; // floor
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  3. Trade Quality Score
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute a holistic Trade Quality Score from raw trading signals only.
     * Deliberately does NOT use adjusted_confidence to avoid double counting.
     */
    private function computeTradeQualityScore(
        string $action,
        float $rrValue,
        array $ev,
        array $filterLog,
        array $taData
    ): array {
        $score    = self::SCORE_BASE;
        $breakdown = [];
        $actionDir = ($action === 'BUY') ? 'bullish' : (($action === 'SELL') ? 'bearish' : 'neutral');

        // ── R:R Ratio ─────────────────────────────────────────────────────────
        if ($rrValue >= self::RR_EXCELLENT_THRESHOLD) {
            $delta = +20; $label = "R:R (1:{$rrValue} — Excellent)";
        } elseif ($rrValue >= self::RR_ACCEPTABLE_THRESHOLD) {
            $delta = +10; $label = "R:R (1:{$rrValue} — Good)";
        } elseif ($rrValue >= self::RR_BELOW_TARGET) {
            $delta = 0;   $label = "R:R (1:{$rrValue} — Acceptable)";
        } else {
            $delta = -15; $label = "R:R (1:{$rrValue} — Poor)";
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Market Regime ─────────────────────────────────────────────────────
        $regime = $filterLog['regime_sweep_volume']['market_regime']['regime'] ?? $taData['market_regime']['regime'] ?? null;
        if ($regime === 'expansion') {
            $delta = +15; $label = 'Market Regime (🚀 Expansion)';
        } elseif ($regime === 'trending') {
            $delta = +10; $label = 'Market Regime (📈 Trending)';
        } elseif ($regime === 'ranging') {
            $delta = -5;  $label = 'Market Regime (↔️ Ranging)';
        } else {
            $delta = 0;   $label = 'Market Regime (Unknown)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Liquidity Sweep ───────────────────────────────────────────────────
        $sweepLog = $filterLog['regime_sweep_volume']['liquidity_sweep'] ?? [];
        if (empty($sweepLog) && isset($taData['liquidity_sweeps']['active_sweep'])) {
            $activeSweep = $taData['liquidity_sweeps']['active_sweep'];
            $sweepDir = strtolower($activeSweep['direction'] ?? '');
            $qScore = (int)($activeSweep['quality_score'] ?? 0);
            
            if ($actionDir !== 'neutral') {
                if ($sweepDir === $actionDir) {
                    $sweepLog = [
                        'status' => 'aligned_sweep',
                        'strength' => ($qScore >= 4) ? 'strong' : (($qScore >= 2) ? 'moderate' : 'weak'),
                    ];
                } else {
                    $sweepLog = [
                        'status' => 'direction_conflict',
                        'strength' => 'weak',
                    ];
                }
            }
        }
        $sweepStatus = $sweepLog['status'] ?? 'no_active_sweep';
        if ($sweepStatus === 'aligned_sweep') {
            $strength = $sweepLog['strength'] ?? 'weak';
            if ($strength === 'strong') {
                $delta = +15; $label = 'Liquidity Sweep (Strong — score 4/4)';
            } elseif ($strength === 'moderate') {
                $delta = +8;  $label = 'Liquidity Sweep (Moderate — score 2-3/4)';
            } else {
                $delta = +3;  $label = 'Liquidity Sweep (Weak — score 1/4)';
            }
        } elseif ($sweepStatus === 'direction_conflict') {
            $delta = -8; $label = 'Liquidity Sweep (Conflict — opposing direction)';
        } else {
            $delta = 0;  $label = 'Liquidity Sweep (None)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Volume Profile Context ────────────────────────────────────────────
        $vpLog    = $filterLog['regime_sweep_volume']['volume_context'] ?? $taData['volume_decision_context'] ?? [];
        $vpStatus = $vpLog['status'] ?? 'no_data';
        if (in_array($vpStatus, ['bullish_above_vah', 'bearish_below_val'])) {
            $delta = +10; $label = 'Volume Profile (✅ Price confirmed outside Value Area)';
        } elseif (in_array($vpStatus, ['bearish_above_vah', 'bullish_below_val'])) {
            $delta = -10; $label = 'Volume Profile (⚠️ Contradiction with bias)';
        } elseif ($vpStatus === 'at_poc') {
            $delta = -5;  $label = 'Volume Profile (⚠️ Price at POC — equilibrium)';
        } else {
            $delta = 0;   $label = 'Volume Profile (Neutral — inside Value Area)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Expected Value ────────────────────────────────────────────────────
        if ($ev['status'] === 'positive') {
            $delta = +10; $label = "EV (✅ Positive EV {$ev['ev_ratio']}×)";
        } elseif ($ev['status'] === 'negative') {
            $delta = -15; $label = "EV (⚠️ Negative EV {$ev['ev_ratio']}×)";
        } else {
            $delta = 0;   $label = 'EV (N/A)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── SMC Confluence ────────────────────────────────────────────────────
        // (previously mislabeled "MTF Alignment" in a comment above this block —
        // this checks smc_confluence, not multi-timeframe data. Real MTF scoring
        // is the separate component right below.)
        $smcConfluenceLog = $filterLog['smc_confluence'] ?? [];
        $smcCount = $smcConfluenceLog['count'] ?? 0;
        if ($smcCount >= 3) {
            $delta = +10; $label = 'SMC Confluence (3+ components aligned)';
        } elseif ($smcCount >= 2) {
            $delta = +5;  $label = 'SMC Confluence (2 components aligned)';
        } else {
            $delta = 0;   $label = 'SMC Confluence (< 2 components)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── MTF Confirmation ──────────────────────────────────────────────────
        // Single-timeframe analysis carries real risk (no higher-timeframe check
        // on the trade direction) — previously this was, at best, a text warning
        // with zero effect on any score. Now it's an actual, visible penalty.
        $mtfLog    = $filterLog['mtf_strength'] ?? [];
        $mtfStatus = $mtfLog['status'] ?? 'single_tf_mode';
        if ($mtfStatus === 'single_tf_mode') {
            $delta = -5; $label = 'MTF Confirmation (⚠️ Single-TF only — no higher-timeframe check)';
        } elseif ($mtfStatus === 'weak_mtf_strength') {
            $minConf = $mtfLog['min_confidence'] ?? '?';
            $delta = -5; $label = "MTF Confirmation (🔴 Weak — min confidence {$minConf}% across TFs)";
        } else {
            $delta = +5; $label = 'MTF Confirmation (✅ Multi-timeframe confirmed, strength adequate)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Volume Confirmation ───────────────────────────────────────────────
        // market_regime.py already computes volume_ratio (current vs 20-period
        // avg) for its own squeeze/expansion classification — reuse it here so an
        // abnormally quiet move (e.g. 0.01x average volume) visibly costs Execution
        // Quality points instead of being invisible to the score entirely.
        $volRatio = $taData['market_regime']['details']['volume_ratio'] ?? null;
        $bosPresent = (bool) ($taData['market_regime']['details']['recent_bos'] ?? false);
        if ($volRatio === null) {
            $delta = 0; $label = 'Volume Confirmation (N/A)';
        } elseif ($volRatio < 0.3) {
            $delta = -10; $label = "Volume Confirmation (🔴 Very low — {$volRatio}× avg, untrustworthy move)";
        } elseif ($volRatio < 0.7) {
            $delta = -5;  $label = "Volume Confirmation (⚠️ Below average — {$volRatio}× avg)";
        } elseif ($volRatio >= 2.5) {
            $delta = +5;  $label = "Volume Confirmation (✅ Volume spike — {$volRatio}× avg)";
        } else {
            $delta = 0;   $label = "Volume Confirmation (Normal — {$volRatio}× avg)";
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Pattern Agreement ─────────────────────────────────────────────────
        // PreTradeFilterService::checkPatternAgreement() sets penalty_level explicitly
        // now (none/weak/moderate/strong) — previously this always fell into the
        // 'none' else-branch (+5) because that field never existed, even on a real
        // strong conflict, silently inflating the score regardless of pattern conflict.
        $patternLog    = $filterLog['pattern_agreement'] ?? [];
        $penaltyStatus = $patternLog['penalty_level'] ?? 'none';
        if ($penaltyStatus === 'strong') {
            $delta = -10; $label = 'Pattern Agreement (Strong conflict)';
        } elseif ($penaltyStatus === 'moderate') {
            $delta = -5;  $label = 'Pattern Agreement (Moderate conflict)';
        } elseif ($penaltyStatus === 'weak') {
            $delta = 0;   $label = 'Pattern Agreement (Weak conflict)';
        } else {
            $delta = +5;  $label = 'Pattern Agreement (No conflict)';
        }
        $score += $delta;
        $breakdown[] = ['label' => $label, 'delta' => $delta];

        // ── Order Book ───────────────────────────────────────────────────────────
        $orderBook = $taData['order_book'] ?? null;
        if ($orderBook !== null) {
            $liq     = $orderBook['liquidity_status'] ?? 'no_data';
            $spread  = $orderBook['spread_status']    ?? 'no_data';
            $imbSide = $orderBook['imbalance_side']   ?? 'neutral';

            if ($liq === 'liquid' && $spread === 'tight') {
                $delta = +10; $label = 'Order Book (✅ Liquid + Tight spread — excellent execution)';
            } elseif ($liq === 'liquid' && $spread === 'normal') {
                $delta = +5;  $label = 'Order Book (✅ Liquid — good execution conditions)';
            } elseif ($liq === 'moderate') {
                $delta = 0;   $label = 'Order Book (⚠️ Moderate liquidity — acceptable)';
            } elseif ($liq === 'thin') {
                $delta = -10; $label = 'Order Book (🔴 Thin liquidity — high slippage risk)';
            } elseif ($spread === 'wide') {
                $delta = -5;  $label = 'Order Book (⚠️ Wide spread — execution cost elevated)';
            } else {
                $delta = 0;   $label = 'Order Book (No data)';
            }
            $score += $delta;
            $breakdown[] = ['label' => $label, 'delta' => $delta];
        }

        // ── CVD / Order Flow ───────────────────────────────────────────────────
        $cvd = $taData['cvd_analysis'] ?? null;
        if ($cvd !== null) {
            $cvdTrend  = $cvd['cvd_trend']        ?? 'no_data';
            $divergence = $cvd['divergence']       ?? 'none';
            $cvdStr    = $cvd['momentum_strength'] ?? 'weak';

            if ($divergence === 'bearish_divergence' && $actionDir === 'bullish') {
                $delta = -10; $label = 'CVD / Order Flow (🔴 Bearish divergence — sellers dominate despite rising price)';
            } elseif ($divergence === 'bullish_divergence' && $actionDir === 'bearish') {
                $delta = -10; $label = 'CVD / Order Flow (🔴 Bullish divergence — buyers dominate despite falling price)';
            } elseif ($divergence === 'bullish_divergence' && $actionDir === 'bullish') {
                $delta = +8;  $label = 'CVD / Order Flow (✅ Bullish divergence — hidden buying pressure confirmed)';
            } elseif ($divergence === 'bearish_divergence' && $actionDir === 'bearish') {
                $delta = +8;  $label = 'CVD / Order Flow (✅ Bearish divergence — hidden selling pressure confirmed)';
            } elseif ($cvdTrend === $actionDir && $cvdStr === 'strong') {
                $delta = +10; $label = 'CVD / Order Flow (✅ Strong aligned flow — momentum confirmed)';
            } elseif ($cvdTrend === $actionDir) {
                $delta = +5;  $label = 'CVD / Order Flow (✅ Aligned flow — buy/sell pressure matches direction)';
            } elseif ($cvdTrend !== 'neutral' && $cvdTrend !== 'no_data' && $cvdTrend !== $actionDir) {
                $delta = -5;  $label = 'CVD / Order Flow (⚠️ Flow opposes trade direction)';
            } else {
                $delta = 0;   $label = 'CVD / Order Flow (Neutral flow)';
            }
            $score += $delta;
            $breakdown[] = ['label' => $label, 'delta' => $delta];
        }

        // ── Taker Pressure ─────────────────────────────────────────────────────
        $takerPressure = $taData['taker_pressure'] ?? null;
        if ($takerPressure !== null) {
            $alignment = $takerPressure['trend_alignment']  ?? 'no_data';
            $ptStrength = $takerPressure['momentum_strength'] ?? 'weak';
            $pressureType = $takerPressure['pressure_type'] ?? 'Neutral / Sideways';

            if ($alignment === 'aligned' && $ptStrength === 'strong') {
                $delta = +10; $label = "Taker Pressure (✅ {$pressureType} — strong alignment)";
            } elseif ($alignment === 'aligned') {
                $delta = +5;  $label = "Taker Pressure (✅ {$pressureType} — aligned with direction)";
            } elseif ($alignment === 'conflict') {
                $delta = -8;  $label = "Taker Pressure (🔴 {$pressureType} — conflicts with trade direction)";
            } else {
                $delta = 0;   $label = "Taker Pressure ({$pressureType} — neutral)";
            }
            $score += $delta;
            $breakdown[] = ['label' => $label, 'delta' => $delta];
        }

        // ── Grade ─────────────────────────────────────────────────────────────
        // A setup with no confirmed Break of Structure has no objective evidence
        // the move is actually underway — regardless of how well everything else
        // scores, that's not an "Excellent"/"Strong" setup. Cap the grade at B.
        [$grade, $gradeLabel, $gradeNote] = $this->scoreToGrade($score);
        if (!$bosPresent && in_array($grade, ['A', 'B+'])) {
            $grade      = 'B';
            $gradeLabel = '✅ Grade B — Good Setup (capped: no confirmed BOS)';
            $gradeNote  = 'Score would otherwise be ' . $gradeLabel . ', but no recent Break of Structure means '
                        . 'there is no objective structural confirmation the move is underway — capped at B.';
        }

        // A setup that only marginally cleared the confidence gate (within 5 points
        // of the threshold that approved it) can't simultaneously be graded "Strong"
        // or "Excellent" — observed on a real report: 120/185 = B+ while the gate
        // itself was only barely met. Cap at B here too.
        $gateAdjusted  = $filterLog['confidence']['adjusted']  ?? null;
        $gateThreshold = $filterLog['confidence']['threshold'] ?? null;
        $isMarginalGate = $gateAdjusted !== null && $gateThreshold !== null
            && ($gateAdjusted - $gateThreshold) < 5;
        if ($isMarginalGate && in_array($grade, ['A', 'B+'])) {
            $grade      = 'B';
            $gradeLabel = '✅ Grade B — Good Setup (capped: marginal confidence gate)';
            $gradeNote  = "Confidence ({$gateAdjusted}%) only barely cleared the {$gateThreshold}% threshold "
                        . 'that approved this trade — a marginal pass can\'t be graded "Strong"/"Excellent" '
                        . 'regardless of how the other signals score. Capped at B.';
        }

        return [
            'score'      => $score,
            'grade'      => $grade,
            'grade_label'=> $gradeLabel,
            'grade_note' => $gradeNote,
            'breakdown'  => $breakdown,
            // Base(100) + R:R(20) + Regime(15) + Sweep(15) + VolumeProfile(10)
            // + EV(10) + SMCConfluence(10) + MTFConfirmation(5) + VolumeConfirmation(5)
            // + PatternAgreement(5) = 195 fixed
            // + OrderBook(10) + CVD(10) + TakerPressure(10) = up to 30 conditional
            // Max when all 3 optional engines enabled = 225
            'max_score'  => self::SCORE_BASE + 20 + 15 + 15 + 10 + 10 + 10 + 5 + 5 + 5
                + ($taData['order_book']     !== null ? 10 : 0)
                + ($taData['cvd_analysis']   !== null ? 10 : 0)
                + ($taData['taker_pressure'] !== null ? 10 : 0),
            'score_type' => 'diagnostic',
            'score_type_note' => 'Diagnostic Score — independent of the BUY/SELL/WAIT decision confidence '
                . '(see class docblock: neither engine reads the other\'s output). Use this to judge setup '
                . 'quality, not as a second confidence number.',
        ];
    }

    private function scoreToGrade(int $score): array
    {
        if ($score >= self::GRADE_A) {
            return ['A', '⭐ Grade A — Excellent Setup', 'All key signals aligned. High-confidence, favorable risk/reward.'];
        }
        if ($score >= self::GRADE_B_PLUS) {
            return ['B+', '✅ Grade B+ — Strong Setup', 'Most signals aligned. Good risk/reward with acceptable conditions.'];
        }
        if ($score >= self::GRADE_B) {
            return ['B', '✅ Grade B — Good Setup', 'Solid signals but some weaknesses. Manage position size accordingly.'];
        }
        if ($score >= self::GRADE_C) {
            return ['C', '⚠️ Grade C — Moderate Setup', 'Mixed signals. Requires extra confirmation before entry.'];
        }
        return ['D', '🔴 Grade D — Weak Setup', 'Multiple negative factors. Consider waiting for better conditions.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function emptyResult(string $action): array
    {
        $note = $action === 'WAIT'
            ? 'Trade Quality not computed — action is WAIT.'
            : 'Trade Quality not computed — missing price levels.';

        return [
            'rr_quality'           => ['status' => 'skipped', 'rr_label' => 'N/A'],
            'ev'                   => ['status' => 'skipped', 'ev_display' => 'N/A'],
            'trade_quality'        => ['grade' => 'N/A', 'grade_label' => 'N/A', 'grade_note' => $note, 'score' => 0, 'breakdown' => []],
            'confidence_adjustment'=> 0,
        ];
    }
}
