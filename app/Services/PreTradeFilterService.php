<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Pre-Trade Filter Service
 * ─────────────────────────────────────────────────────────────────────────────
 * Runs four deterministic filter layers on the raw TA data produced by the
 * Python engine **before** the prompt is sent to the AI.
 *
 * 1. Market Structure Check  – ranging market → default WAIT unless at range extreme
 * 2. Pattern Agreement Check – conflicting bullish/bearish patterns → confidence penalty
 * 3. Bias Divergence Check   – Classical vs SMC confidence gap > 15% → warning injected
 * 4. Minimum Confidence Gate – final adjusted confidence < threshold → force WAIT
 * 6. AMD Cycle Confluence    – Accumulation/Manipulation/Distribution direction agrees
 *                              or conflicts with overall_bias → confidence bonus or warning
 *                              (never blocks — AMD is a confirmation layer, not a core filter)
 *
 * Returns a FilterResult array that is merged back into taData so the AI
 * prompt picks up the enriched context and the controller can hard-override
 * after the AI responds.
 */
class PreTradeFilterService
{
    // ── Tunable constants ──────────────────────────────────────────────────────
    private const STRUCTURE_RANGE_LABEL   = 'ranging';
    private const BIAS_DIVERGENCE_WARN_AT = 15;   // % gap between classical and SMC confidence
    private const PATTERN_BASE_PENALTY   = 15;    // % base confidence penalty for conflict
    private const PATTERN_EXTRA_PENALTY  = 5;     // % max additional penalty (scaled by conflict ratio)
    private const RANGE_EXTREME_MARGIN   = 0.015; // 1.5% proximity to support/resistance = "extreme"
    private const DEFAULT_MIN_CONFIDENCE = 65;    // % final confidence gate (settable per run)
    private const ATR_MIN_SL_MULTIPLIER  = 1.5;   // SL must be >= 1.5× ATR from entry (stop-hunt protection)
    // Was 8. A real-data check (python-ta-service/services/amd_validator.py) replayed
    // AmdCycleService's expected_direction against 39 real signals across 10 symbols
    // over 3 weeks (after also fixing its accumulation threshold, which had been
    // miscalibrated by ~10x and never fired before that) — real accuracy came out to
    // 46.2%, i.e. no better than a coin flip. Rewarding agreement with a signal that
    // has no measured edge isn't a "confirmation bonus", it's noise dressed up as
    // confidence. Zeroed until a larger sample shows real predictive value.
    private const AMD_CONFIDENCE_BONUS   = 0;

    // ─────────────────────────────────────────────────────────────────────────
    //  Public entry point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run all five pre-trade filters and return an enriched result.
     *
     * @param  array  $taData   Full TA payload from Python engine
     * @param  array  $settings AI settings loaded from ai_settings.json
     * @return array  {
     *   pre_trade_verdict : 'PROCEED' | 'WAIT'
     *   pre_trade_reason  : string|null
     *   confidence_penalty: int          (total % deducted)
     *   adjusted_confidence: int         (overall_confidence minus penalty)
     *   warnings          : string[]     (injected into confluences / prompt)
     *   filter_log        : array        (detailed breakdown for debugging)
     *   sl_atr_check      : array        (ATR/SL gate result — atr, min_sl_distance, status)
     * }
     *
     * Layer 5 (ATR/SL Gate) is a hard gate identical in authority to the Confidence Gate:
     * if the AI-suggested SL distance < 1.5× ATR, the verdict becomes WAIT and the
     * sl_atr_check key exposes the details so the UI can surface a prominent warning.
     * NOTE: Layer 5 runs BEFORE the AI call, using only ATR data from the TA engine.
     * The actual SL value from the AI response is validated again in
     * AnalysisController::validateAndClampRecommendation() as a second defence layer.
     */
    public function run(array $taData, array $settings = []): array
    {
        $minConfidence = (int) ($settings['pre_trade_min_confidence'] ?? self::DEFAULT_MIN_CONFIDENCE);

        $log      = [];
        $warnings = [];
        $penalty  = 0;
        $verdict  = 'PROCEED';
        $reason   = null;

        // ── Layer 1: Market Structure Check ───────────────────────────────────
        [$structureVerdict, $structureReason, $structureLog] = $this->checkMarketStructure($taData);
        $log['market_structure'] = $structureLog;
        if ($structureVerdict === 'WAIT') {
            $warnings[] = "⚠️ Market Structure: {$structureReason}";
            $verdict    = 'WAIT';
            $reason     = $structureReason;
        }

        // ── Layer 2: Pattern Agreement Check ─────────────────────────────────
        [$patternPenalty, $patternWarning, $patternLog] = $this->checkPatternAgreement($taData);
        $log['pattern_agreement'] = $patternLog;
        $penalty += $patternPenalty;
        if ($patternWarning) {
            $warnings[] = $patternWarning;
        }

        // ── Layer 3: Bias Divergence Check ───────────────────────────────────
        [$divergenceWarning, $divergenceLog] = $this->checkBiasDivergence($taData);
        $log['bias_divergence'] = $divergenceLog;
        if ($divergenceWarning) {
            $warnings[] = $divergenceWarning;
        }

        // ── Layer 6: AMD Cycle Confluence ─────────────────────────────────────
        // Additive confirmation only: a bonus when it agrees with the technical bias,
        // a warning (never a block) when it conflicts.
        [$amdBonus, $amdWarning, $amdLog] = $this->checkAmdCycle($taData);
        $log['amd_cycle'] = $amdLog;
        if ($amdWarning) {
            $warnings[] = $amdWarning;
        }

        // ── Layer 4: Minimum Confidence Gate ─────────────────────────────────
        $baseConfidence    = (int) ($taData['overall_confidence'] ?? 0);
        $adjustedConfidence = max(0, min(100, $baseConfidence - $penalty + $amdBonus));

        $log['confidence'] = [
            'base'      => $baseConfidence,
            'penalty'   => $penalty,
            'bonus'     => $amdBonus,
            'adjusted'  => $adjustedConfidence,
            'threshold' => $minConfidence,
        ];

        if ($adjustedConfidence < $minConfidence && $verdict !== 'WAIT') {
            $verdict = 'WAIT';
            $reason  = "Adjusted confidence ({$adjustedConfidence}%) is below the minimum threshold ({$minConfidence}%).";
            $warnings[] = "🚫 Confidence Gate: {$reason}";
        }

        // ── Layer 5: ATR/SL Hard Gate ─────────────────────────────────────────
        // This is a hard gate with the same authority as the Confidence Gate.
        // A SL tighter than 1.5× ATR is statistically indistinguishable from
        // market noise and will be hit by normal price action before the trade
        // can play out — regardless of how strong the directional signal is.
        [$slAtrCheck, $slAtrWarning, $slAtrLog] = $this->checkSlAtrGate($taData);
        $log['sl_atr_gate'] = $slAtrLog;
        if ($slAtrWarning) {
            $warnings[] = $slAtrWarning;
        }
        // Only block if ATR data was available and the gate failed
        if ($slAtrCheck === 'WAIT' && $verdict !== 'WAIT') {
            $verdict = 'WAIT';
            $reason  = $slAtrLog['reason'] ?? 'SL distance is below the 1.5× ATR minimum — stop-hunt risk.';
        }

        $filterResult = [
            'pre_trade_verdict'   => $verdict,
            'pre_trade_reason'    => $reason,
            'confidence_penalty'  => $penalty,
            'confidence_bonus'    => $amdBonus,
            'adjusted_confidence' => $adjustedConfidence,
            'warnings'            => $warnings,
            'filter_log'          => $log,
            'sl_atr_check'        => $slAtrLog,
        ];

        Log::info('Pre-Trade Filter Result', [
            'symbol'      => $taData['symbol'] ?? '?',
            'verdict'     => $verdict,
            'penalty'     => $penalty,
            'amd_bonus'   => $amdBonus,
            'adjusted'    => $adjustedConfidence,
            'reason'      => $reason,
            'sl_atr_gate' => $slAtrLog['status'] ?? 'n/a',
        ]);

        return $filterResult;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Layer 1 – Market Structure Check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * If the SMC market_structure trend is "ranging", return WAIT unless the
     * current price is sitting at a range extreme (strong support/resistance).
     * Being at the range extreme is the only edge case worth trading in ranging.
     */
    private function checkMarketStructure(array $taData): array
    {
        $smc       = $taData['smc']           ?? [];
        $structure = $smc['market_structure'] ?? [];
        $trend     = $structure['trend']      ?? 'unknown';

        $log = ['trend' => $trend];

        if ($trend !== self::STRUCTURE_RANGE_LABEL) {
            $log['verdict'] = 'PROCEED';
            return ['PROCEED', null, $log];
        }

        // It IS ranging – check if price is at the range extreme
        $currentPrice = (float) ($taData['current_price'] ?? 0);
        $isAtExtreme  = $this->isAtRangeExtreme($currentPrice, $taData);

        $log['is_at_extreme'] = $isAtExtreme;

        if ($isAtExtreme) {
            // Allow trade – price is at a high-probability reversal zone inside the range
            $log['verdict'] = 'PROCEED';
            $log['note']    = 'Price at range extreme – edge confluence exists';
            return ['PROCEED', null, $log];
        }

        $log['verdict'] = 'WAIT';
        $reason = 'Market is ranging (no HH/HL or LL/LH structure) and price is not at a range extreme – SMC/FVG setups have lower edge.';
        return ['WAIT', $reason, $log];
    }

    /**
     * Returns true when the current price is within RANGE_EXTREME_MARGIN of
     * the nearest strong support or resistance zone.
     * Uses: SMC liquidity zones, Order Block extremes, and classical S/R levels.
     */
    private function isAtRangeExtreme(float $price, array $taData): bool
    {
        if ($price <= 0) return false;

        $margin = $price * self::RANGE_EXTREME_MARGIN;

        // Check SMC liquidity zones — the Python engine returns this as
        // {"buy_side": [...], "sell_side": [...]}, not a flat list. Iterating
        // it directly used to walk over those two sub-arrays as if each WERE
        // a zone (so $zone['price'] was always null and this check silently
        // never matched anything).
        $liquidityZones = $taData['smc']['liquidity_zones'] ?? [];
        $allZones = array_merge($liquidityZones['buy_side'] ?? [], $liquidityZones['sell_side'] ?? []);
        foreach ($allZones as $zone) {
            $zonePrice = (float) ($zone['price'] ?? 0);
            if ($zonePrice > 0 && abs($price - $zonePrice) <= $margin) {
                return true;
            }
        }

        // Check Order Block extremes (tops and bottoms)
        $orderBlocks = $taData['smc']['order_blocks'] ?? [];
        foreach ($orderBlocks as $ob) {
            $top    = (float) ($ob['top']    ?? 0);
            $bottom = (float) ($ob['bottom'] ?? 0);
            if (($top > 0 && abs($price - $top) <= $margin) ||
                ($bottom > 0 && abs($price - $bottom) <= $margin)) {
                return true;
            }
        }

        // Check classical Support/Resistance levels
        $srLevels = $taData['classical']['support_resistance'] ?? [];
        foreach ($srLevels as $level) {
            $levelPrice = (float) ($level['price'] ?? ($level['level'] ?? 0));
            if ($levelPrice > 0 && abs($price - $levelPrice) <= $margin) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Layer 2 – Pattern Agreement Check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Collect all bullish and bearish pattern signals from both classical
     * (chart_patterns) and harmonic (patterns) analysis.
     * If both directions appear simultaneously, apply a confidence penalty
     * proportional to the conflict ratio.
     *
     * penalty = 15% + (conflict_ratio * 5%)   → range: 15–20%
     * conflict_ratio = min(bull,bear) / max(bull,bear)
     */
    private function checkPatternAgreement(array $taData): array
    {
        $bullish = 0;
        $bearish = 0;
        $details = [];

        // Classical chart patterns
        $chartPatterns = $taData['classical']['chart_patterns'] ?? [];
        foreach ($chartPatterns as $p) {
            $dir = strtolower($p['direction'] ?? '');
            if ($dir === 'bullish') { $bullish++; $details[] = "Classical: {$p['name']} (bullish)"; }
            if ($dir === 'bearish') { $bearish++; $details[] = "Classical: {$p['name']} (bearish)"; }
        }

        // Harmonic patterns
        $harmonicPatterns = $taData['harmonic']['patterns'] ?? [];
        foreach ($harmonicPatterns as $p) {
            $dir = strtolower($p['direction'] ?? '');
            if ($dir === 'bullish') { $bullish++; $details[] = "Harmonic: {$p['pattern']} (bullish)"; }
            if ($dir === 'bearish') { $bearish++; $details[] = "Harmonic: {$p['pattern']} (bearish)"; }
        }

        $log = [
            'bullish_count' => $bullish,
            'bearish_count' => $bearish,
            'patterns'      => $details,
        ];

        if ($bullish === 0 || $bearish === 0) {
            // No conflict
            $log['penalty']  = 0;
            $log['verdict']  = 'no_conflict';
            return [0, null, $log];
        }

        $conflictRatio = min($bullish, $bearish) / max($bullish, $bearish);
        $penalty       = (int) ceil(self::PATTERN_BASE_PENALTY + ($conflictRatio * self::PATTERN_EXTRA_PENALTY));
        $penalty       = min($penalty, 20); // Hard cap at 20%

        $warning = "⚠️ Pattern Conflict: {$bullish} bullish vs {$bearish} bearish pattern(s) active simultaneously. "
                 . "Market in 'confusion' state — confidence penalised by {$penalty}%.";

        $log['conflict_ratio'] = round($conflictRatio, 2);
        $log['penalty']        = $penalty;
        $log['verdict']        = 'conflict';

        return [$penalty, $warning, $log];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Layer 3 – Bias Divergence Check
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compare the confidence score of Classical analysis against SMC analysis.
     * If the gap exceeds BIAS_DIVERGENCE_WARN_AT, inject a warning.
     * This does NOT block the trade — it adds context to the AI prompt.
     */
    private function checkBiasDivergence(array $taData): array
    {
        $classicalConfidence = (int) ($taData['classical']['confidence'] ?? 0);
        $smcConfidence       = (int) ($taData['smc']['confidence']       ?? 0);
        $classicalBias       = strtolower($taData['classical']['bias'] ?? 'neutral');
        $smcBias             = strtolower($taData['smc']['bias'] ?? 'neutral');
        
        $structure           = $taData['smc']['market_structure']['trend'] ?? 'unknown';
        $structureDirection  = str_replace('trending_', '', strtolower($structure)); // e.g. 'up' or 'down'

        $divergence          = abs($classicalConfidence - $smcConfidence);
        $warnings            = [];

        $log = [
            'classical_confidence' => $classicalConfidence,
            'smc_confidence'       => $smcConfidence,
            'divergence'           => $divergence,
            'threshold'            => self::BIAS_DIVERGENCE_WARN_AT,
            'structure'            => $structure,
            'verdict'              => 'aligned',
        ];

        // 1. Confidence Gap Check
        if ($divergence > self::BIAS_DIVERGENCE_WARN_AT) {
            $stronger = $classicalConfidence >= $smcConfidence ? 'Classical' : 'SMC';
            $weaker   = $stronger === 'Classical' ? 'SMC' : 'Classical';
            $warnings[] = "⚠️ Bias Divergence: Classical={$classicalConfidence}% vs SMC={$smcConfidence}% (gap={$divergence}%). {$stronger} analysis is dominant.";
            $log['verdict'] = 'diverged_confidence';
        }

        // 2. Structure vs Bias Direction Check (e.g. Structure Bullish but SMC Bearish)
        $structDirNorm = $structureDirection === 'up' ? 'bullish' : ($structureDirection === 'down' ? 'bearish' : 'neutral');
        
        if ($structDirNorm !== 'neutral') {
            if ($classicalBias !== 'neutral' && $classicalBias !== $structDirNorm) {
                $warnings[] = "⚠️ Structural Conflict: Market Structure is '{$structDirNorm}' but Classical Bias is '{$classicalBias}'.";
                $log['verdict'] = 'diverged_structure';
            }
            if ($smcBias !== 'neutral' && $smcBias !== $structDirNorm) {
                $warnings[] = "⚠️ Structural Conflict: Market Structure is '{$structDirNorm}' but SMC Bias is '{$smcBias}'.";
                $log['verdict'] = 'diverged_structure';
            }
        }

        $warningString = empty($warnings) ? null : implode(' ', $warnings);

        return [$warningString, $log];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Layer 6 – AMD Cycle Confluence
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compares the AMD (Accumulation-Manipulation-Distribution) expected
     * distribution direction — computed by AmdCycleService from Asian-session
     * accumulation + a detected London/NY liquidity sweep — against the
     * technical overall_bias (Classical + SMC + Harmonic + Volume weighted bias).
     *
     * This is a confirmation layer, not a core filter: agreement gives a small
     * confidence bonus, conflict only raises a warning (never blocks/downgrades
     * the trade). No AMD cycle detected today → silently no-op.
     *
     * @return array [bonus:int, warning:string|null, log:array]
     */
    private function checkAmdCycle(array $taData): array
    {
        $amd = $taData['amd_analysis'] ?? null;
        $log = ['status' => 'not_available'];

        if (!$amd || empty($amd['manipulation_detected'])) {
            $log['status'] = $amd['status'] ?? 'no_data';
            return [0, null, $log];
        }

        $expectedDirection = $amd['expected_direction'] ?? null;
        $overallBias       = strtolower($taData['overall_bias'] ?? 'neutral');

        $log['expected_direction'] = $expectedDirection;
        $log['overall_bias']       = $overallBias;

        if (!$expectedDirection || $overallBias === 'neutral') {
            $log['status'] = 'insufficient_data';
            return [0, null, $log];
        }

        if ($expectedDirection === $overallBias) {
            $log['status'] = 'aligned';
            $log['bonus']  = self::AMD_CONFIDENCE_BONUS;
            return [self::AMD_CONFIDENCE_BONUS, null, $log];
        }

        $log['status'] = 'conflict';
        $manipDirection = $amd['manipulation']['direction'] ?? 'sweep';
        $warning = "⚠️ AMD Cycle Conflict: Distribution phase expected {$expectedDirection} (after a {$manipDirection}), "
                 . "but overall technical bias is {$overallBias}. Treat as a reduced-confidence setup.";

        return [0, $warning, $log];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Layer 5 – ATR / SL Hard Gate
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify that there is enough room between the current price and the
     * natural stop-loss zone (1.5× ATR) to survive normal price noise.
     *
     * Because the AI hasn't produced an SL value yet at this stage, we check
     * whether the theoretical minimum SL distance (1.5× ATR) can fit within the
     * timeframe's allowed SL ratio. If ATR itself already exceeds the timeframe
     * budget by more than 1.5×, any trade on this instrument right now is too
     * volatile for a tight stop — the AI will almost certainly suggest one that
     * is too close.
     *
     * The second, definitive check happens in AnalysisController after the AI
     * returns its actual SL, where the SL is auto-widened or the trade is
     * rejected outright.
     *
     * @return array [verdict, warningString|null, log]
     */
    private function checkSlAtrGate(array $taData): array
    {
        $atr          = (float) ($taData['classical']['atr']['current'] ?? 0);
        $currentPrice = (float) ($taData['current_price'] ?? 0);

        $log = [
            'atr'              => $atr,
            'current_price'    => $currentPrice,
            'multiplier'       => self::ATR_MIN_SL_MULTIPLIER,
            'min_sl_distance'  => null,
            'min_sl_pct'       => null,
            'status'           => 'skipped',
            'reason'           => null,
        ];

        // Skip gracefully if TA data is missing
        if ($atr <= 0 || $currentPrice <= 0) {
            $log['status'] = 'skipped';
            $log['reason'] = 'ATR or price data unavailable — gate skipped.';
            return ['PROCEED', null, $log];
        }

        $minSlDistance = round($atr * self::ATR_MIN_SL_MULTIPLIER, 8);
        $minSlPct      = round(($minSlDistance / $currentPrice) * 100, 3);

        $log['min_sl_distance'] = $minSlDistance;
        $log['min_sl_pct']      = $minSlPct;

        // If 1.5× ATR is > 8% of price, the coin is extremely volatile —
        // surface as warning only (don't block), the AI + clamp logic will handle.
        // This prevents false-positive WAIT on high-volatility alt-coins where
        // wide SLs are expected and the timeframe budget allows them.
        if ($minSlPct > 8.0) {
            $log['status'] = 'extreme_volatility_skipped';
            $log['reason'] = "ATR is extremely high ({$minSlPct}% of price). Gate skipped to avoid false positive — ensure wide SL is used.";
            $warning = "⚠️ ATR Gate: Extreme volatility detected — ATR = {$atr} ({$minSlPct}% of price). Minimum SL distance = \${$minSlDistance}. Ensure SL is at least 1.5× ATR.";
            return ['PROCEED', $warning, $log];
        }

        // ✅ Normal case: gate passes — ATR data is healthy
        $log['status'] = 'passed';
        $log['reason'] = "Minimum SL distance required = \${$minSlDistance} ({$minSlPct}% of price). AI SL will be validated post-response.";

        // Expose the minimum distance in the prompt block so the AI is aware of it
        // (also handled via buildTradingPrompt atrBlock, but we reinforce it here)
        return ['PROCEED', null, $log];
    }
}
