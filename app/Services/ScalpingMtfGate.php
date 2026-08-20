<?php

namespace App\Services;

/**
 * Pure-Scalping 5m Execution Engine.
 *
 * Timeframe roles (per the user's spec):
 *   1h  (context)   — macro filter. Never independently triggers a trade, but
 *                      DOES carry a hard minimum-confidence floor (see
 *                      MTF_FLOOR_* below) — "alignment" (same direction) is
 *                      not the same thing as "confirmation" (adequate
 *                      confidence). A same-direction-but-weak reading is
 *                      WEAK ALIGNMENT and must not approve a trade.
 *   15m (context)   — intermediate filter, same treatment as 1h.
 *   5m  (execution) — the ONLY timeframe allowed to generate the actual entry
 *                      trigger (BOS/CHoCH/liquidity sweep). Owns direction.
 *
 * This is an ENTRY EXECUTION ENGINE, not a direction-prediction engine — a
 * correct directional bias is necessary but never sufficient. Five things
 * are validated independently and ALL must hold before BUY/SELL:
 * DIRECTION, SETUP, ENTRY (trigger + location), RISK, ROOM (to target before
 * opposing structure). Any single failure — no matter how good the other
 * four look, and regardless of R:R/score/MTF agreement — forces WAIT.
 *
 * Confidence is NOT one additive/multiplicative number layered on top of
 * whatever the AI or deterministic engine already produced. It is computed
 * FROM SCRATCH as a weighted blend of four independent components:
 *
 *   Final Confidence = 40% Execution + 30% Setup Quality
 *                     + 20% Direction + 10% Risk
 *
 * Decision priority (highest first, never overridden by a lower one):
 *   1. Hard blocks (5m self-contradiction, unresolved strong pattern
 *      conflict, insufficient room to target, overextension)
 *   2. MTF confidence floors (entry validity)
 *   3. Structure / location / momentum (Execution Gate)
 *   4. MTF strength (report only, beyond the hard floors)
 *   5. Risk / R:R
 *   6. Confidence gate (>=55%)
 *   7. Supporting confluences (score only, never gates)
 *
 * Pure, unit-testable: takes plain arrays, returns a plain array. No DB/HTTP.
 */
class ScalpingMtfGate
{
    // ── MTF confidence floors — HARD requirements, not just a report label.
    // "MTF ALIGNMENT != MTF CONFIRMATION": same-direction-but-below-these
    // numbers is WEAK ALIGNMENT and must not approve a trade.
    // HTF floor lowered 50->40 — this only affects the AGREEING case (an
    // opposing HTF bias already collapses to 0 via $htfDirConf regardless of
    // where this floor sits, per the direction-aware fix). 1h is declared
    // context-only/never-independently-triggering, so lukewarm-but-agreeing
    // 1h shouldn't carry the same hard veto as active opposition — that
    // belongs in Direction Score (already 20% weighted), not a floor.
    // Reproduced live: ZECUSDT (15m=70%, 5m=75%, both agreeing and strong)
    // was blocked solely by 1h=39% agreeing.
    private const MTF_FLOOR_HTF = 40;
    private const MTF_FLOOR_MTF = 50;
    private const MTF_FLOOR_LTF = 60;

    // ── MTF Alignment classification (report label — STRONG tier is a nicety
    // on top of the hard floors above, not a separate gate) ─────────────────
    private const MTF_STRONG_HTF_MIN = 60;
    private const MTF_STRONG_MTF_MIN = 55;
    private const MTF_STRONG_LTF_MIN = 55;
    private const MTF_MODERATE_MIN_FLOOR = 40;

    // ── Room to target (distance to nearest opposing structure, in ATR) ────
    private const ROOM_HARD_BLOCK_ATR = 0.5;
    private const ROOM_SOFT_BLOCK_ATR = 1.0;

    // ── Overextension — "RSI alone must never CREATE a trade" (it's never a
    // valid entry trigger, see hasStructureEvent-based triggers below), but it
    // CAN independently block one. Thresholds per latest spec revision.
    private const RSI_OVEREXTENDED_HIGH = 75;
    private const RSI_OVEREXTENDED_LOW  = 25;
    private const EMA20_EXTENSION_ATR_MULTIPLE = 2.5;

    // ── Volume-or-momentum floor — low volume may ONLY be bypassed by a real
    // confirmed STRUCTURE trigger (BOS/CHoCH), not a liquidity sweep alone.
    private const VOLUME_MOMENTUM_FLOOR = 0.5;

    // ── Strong pattern conflict (confidence-magnitude based — independent of
    // the softer, trigger-status-based Setup Quality deduction below) ──────
    private const STRONG_PATTERN_CONFIDENCE = 80;

    // ── Hard Risk / Volatility Gate — ATR% beyond this is treated the same as
    // PreTradeFilterService's own "extreme volatility" threshold elsewhere in
    // this codebase (kept consistent rather than inventing a new number).
    private const EXTREME_VOLATILITY_ATR_PCT = 8.0;

    // ── Execution Gate ──────────────────────────────────────────────────────
    private const GATE_MIN_SUBSCORE   = 55; // Execution Score / Setup Quality floor
    private const GATE_MIN_FINAL_CONF = 60; // Final Confidence floor — raised from 55%
    private const GATE_MIN_RR         = 1.5;
    private const GRADE_A_MIN_FINAL   = 70;
    private const GRADE_A_MIN_SUB     = 65;
    private const GRADE_A_MIN_RR      = 2.0;
    // Grade B's floor is GATE_MIN_FINAL_CONF itself (60%) — no separate
    // constant needed now that the gate and Grade B's minimum are identical.

    /**
     * @param  string $action  'BUY' | 'SELL' — the raw directional lean from
     *                         upstream (AI or deterministic engine). This
     *                         gate decides whether that lean is EXECUTABLE.
     * @param  array  $taData  Full LTF (5m/execution) TA payload — htf_data
     *                         (1h) and mtf_data (15m) must be present, caller
     *                         no-ops otherwise.
     * @param  float  $rrValue Parsed numeric R:R already computed upstream.
     * @return array{verdict:string, reason:?string, report:array}
     */
    public function evaluate(string $action, array $taData, float $rrValue): array
    {
        $htf = $taData['htf_data'] ?? [];
        $mtf = $taData['mtf_data'] ?? [];

        $htfBias = strtolower($htf['overall_bias'] ?? 'neutral');
        $htfConf = (int) ($htf['overall_confidence'] ?? 0);
        $mtfBias = strtolower($mtf['overall_bias'] ?? 'neutral');
        $mtfConf = (int) ($mtf['overall_confidence'] ?? 0);
        $ltfBias = strtolower($taData['overall_bias'] ?? 'neutral');
        $ltfConf = (int) ($taData['overall_confidence'] ?? 0);

        $actionDir   = strtoupper($action) === 'BUY' ? 'bullish' : 'bearish';
        $opposingDir = $actionDir === 'bullish' ? 'bearish' : 'bullish';

        // Direction-aware confidence — a raw magnitude check (htfConf >= 50) treats
        // "1h is 70% confident BEARISH" as passing the floor for a BUY exactly the
        // same as "1h is 70% confident BULLISH", even though the former actively
        // opposes the trade. classifyMtfAlignment() below already detects this as
        // CONFLICT for the report label, but nothing previously fed that into the
        // actual gate — the raw floor check enforced magnitude only, never alignment.
        // Same "contribution" pattern already used by directionScore(): confidence
        // only counts toward the floor when the bias it's attached to agrees with
        // the trade's own direction; an opposing reading contributes 0.
        //
        // A genuinely NEUTRAL bias auto-passes its own floor rather than also
        // collapsing to 0 — "no opinion" is not the same as "actively opposing"
        // and must not carry the same hard veto. Currently unreachable in
        // practice (bias_combiner.py always emits bullish/bearish, never
        // neutral) but htf_data/mtf_data default to 'neutral' when entirely
        // absent from the payload, and that missing-data case must not
        // silently hard-block every trade.
        $htfDirConf = $htfBias === $actionDir ? $htfConf : ($htfBias === 'neutral' ? self::MTF_FLOOR_HTF : 0);
        $mtfDirConf = $mtfBias === $actionDir ? $mtfConf : ($mtfBias === 'neutral' ? self::MTF_FLOOR_MTF : 0);
        $ltfDirConf = $ltfBias === $actionDir ? $ltfConf : ($ltfBias === 'neutral' ? self::MTF_FLOOR_LTF : 0);

        $bosBull = $this->hasStructureEvent($taData, 'bullish');
        $bosBear = $this->hasStructureEvent($taData, 'bearish');
        $structureTrigger = $actionDir === 'bullish' ? $bosBull : $bosBear;
        $opposingStructure = $actionDir === 'bullish' ? $bosBear : $bosBull;

        $liquidityTrigger = $this->hasLiquiditySweep($taData, $actionDir);
        [$volumeRatio, $volumeTier] = $this->classifyVolume($taData);

        $mtfAlignment = $this->classifyMtfAlignment(
            $htfBias, $htfConf, $mtfBias, $mtfConf, $ltfBias, $ltfConf, $actionDir,
            $structureTrigger || $liquidityTrigger
        );

        $roomAtr = $this->nearestOpposingStructureAtr($taData, $actionDir);

        $directionScore = $this->directionScore($actionDir, $htfBias, $htfConf, $mtfBias, $mtfConf, $ltfBias, $ltfConf);
        $setupQuality   = $this->setupQuality($taData, $actionDir);
        $executionScore = $this->executionScore($structureTrigger, $liquidityTrigger, $volumeTier);
        $riskScore      = $this->riskScore($rrValue);

        $finalConfidence = (int) round(
            0.40 * $executionScore + 0.30 * $setupQuality + 0.20 * $directionScore + 0.10 * $riskScore
        );

        $report = [
            'raw_lean'                       => strtoupper($action),
            'executable_signal'              => false,
            'grade'                          => null,
            'direction'                      => strtoupper($actionDir),
            'setup'                          => $taData['market_regime']['regime'] ?? 'unknown',
            'entry_trigger'                  => $structureTrigger ? 'CONFIRMED' : ($liquidityTrigger ? 'SWEEP_ONLY' : 'NONE'),
            'entry_location'                 => null, // set below once room is known
            'momentum'                       => $volumeTier ?? 'unknown',
            'execution_confidence'           => $finalConfidence,
            'direction_confidence'           => $directionScore,
            'setup_quality'                  => $setupQuality,
            'execution_score'                => $executionScore,
            'risk_score'                     => $riskScore,
            'mtf_direction'                  => $mtfAlignment['direction'],
            'mtf_strength'                   => $mtfAlignment['strength'],
            'mtf_status'                     => $mtfAlignment['status'],
            'structure_trigger'              => $structureTrigger,
            'liquidity_trigger'              => $liquidityTrigger,
            'volume_ratio'                   => $volumeRatio,
            'volume_tier'                    => $volumeTier,
            'market_regime'                  => $taData['market_regime']['regime'] ?? 'unknown',
            'nearest_opposing_structure_atr' => $roomAtr,
            'hard_blocks'                    => 'FAIL',
            'final_gate'                     => 'BLOCKED',
            'next_trigger_needed'            => null,
            'primary_reason'                 => null,
        ];
        $report['entry_location'] = $roomAtr === null ? 'unknown' : ($roomAtr >= self::ROOM_SOFT_BLOCK_ATR ? 'CLEAR' : 'CONGESTED');

        $atrPct = $this->atrPct($taData);

        // ── Structured validation summary (spec's required report fields) ──
        // Computed once from the same underlying signals the hard blocks
        // below use, independent of control flow, so it's always accurate
        // regardless of which specific block (if any) actually fires first.
        $rsiExtended = $this->isRsiOverextended($taData, $actionDir);
        $emaExtended = $this->isEmaExtended($taData);
        $hasStrongPatternConflict = $this->hasUnresolvedStrongPatternConflict($taData, $structureTrigger);
        $roomOk = $roomAtr === null
            || $roomAtr >= self::ROOM_SOFT_BLOCK_ATR
            || ($roomAtr >= self::ROOM_HARD_BLOCK_ATR && $volumeTier === 'strong' && $structureTrigger);
        $volumeOk = !($volumeRatio !== null && $volumeRatio < self::VOLUME_MOMENTUM_FLOOR && !$structureTrigger);
        $ltfConfirmed = $ltfDirConf >= self::MTF_FLOOR_LTF;
        $mtfConfirmed = $htfDirConf >= self::MTF_FLOOR_HTF && $mtfDirConf >= self::MTF_FLOOR_MTF;
        $entrySetupValid = $ltfBias === $actionDir && $structureTrigger
            && !$opposingStructure && !($bosBull && $bosBear) && !$hasStrongPatternConflict;
        $rrOk = $rrValue >= self::GATE_MIN_RR;
        $volatilityOk = $atrPct === null || $atrPct <= self::EXTREME_VOLATILITY_ATR_PCT;
        $dataOk = (float) ($taData['current_price'] ?? 0) > 0;

        $report['validation'] = [
            'entry_setup'         => $entrySetupValid ? 'VALID' : 'INVALID',
            'entry_trigger'       => $report['entry_trigger'],
            'ltf_confirmation'    => $ltfConfirmed ? 'PASS' : 'FAIL',
            'mtf_confirmation'    => $mtfConfirmed ? 'PASS' : 'FAIL',
            'pattern_conflict'    => $hasStrongPatternConflict ? 'UNRESOLVED' : 'NONE',
            'opposing_structure'  => $roomOk ? 'CLEAR' : 'BLOCKED',
            'extension_filter'    => $emaExtended ? 'FAIL' : 'PASS',
            'volume_filter'       => $volumeOk ? 'PASS' : 'FAIL',
            'rsi_extension'       => $rsiExtended ? 'FAIL' : 'PASS',
            'rr_filter'           => $rrOk ? 'PASS' : 'FAIL',
            'volatility_gate'     => $volatilityOk ? 'PASS' : 'FAIL',
            'data_consistency'    => $dataOk ? 'PASS' : 'FAIL',
            'final_scalping_gate' => ($dataOk && $volatilityOk && $entrySetupValid && $roomOk && $ltfConfirmed
                && $mtfConfirmed && !$rsiExtended && !$emaExtended && $volumeOk && $rrOk
                && $finalConfidence >= self::GATE_MIN_FINAL_CONF
                && $executionScore >= self::GATE_MIN_SUBSCORE && $setupQuality >= self::GATE_MIN_SUBSCORE)
                ? 'PASS' : 'FAIL',
        ];

        // ── Hard block 0a: data validity ────────────────────────────────────
        if ((float) ($taData['current_price'] ?? 0) <= 0) {
            return $this->wait(
                'current_price is missing or invalid — cannot safely evaluate an entry.',
                'Wait for valid price data.',
                'Data consistency error: required price data is missing.',
                $report
            );
        }

        // ── Hard block 0b: hard risk / volatility gate ──────────────────────
        // Extreme ATR% relative to price means normal stop/target distances
        // stop being meaningful — same "extreme volatility" threshold already
        // used elsewhere in this codebase (PreTradeFilterService::checkSlAtrGate),
        // kept consistent rather than inventing a new number.
        if ($atrPct !== null && $atrPct > self::EXTREME_VOLATILITY_ATR_PCT) {
            return $this->wait(
                "ATR is {$atrPct}% of price — extreme volatility, normal risk sizing is not meaningful right now.",
                'Wait for volatility to normalize.',
                'Entry blocked: extreme volatility (hard risk gate).',
                $report
            );
        }

        // ── Hard block 0c: 5m bias must match the proposed action ───────────
        // Basic consistency: "if 5m=BEARISH, BUY=impossible" — this should
        // never actually fire given 5m leads direction upstream, but it's the
        // final backstop so raw direction and entry direction can never
        // silently contradict each other in the report.
        if ($ltfBias !== $actionDir) {
            return $this->wait(
                "5m bias is {$ltfBias}, which cannot support a {$action}.",
                "Wait for 5m bias to actually turn {$actionDir}.",
                "Entry blocked: 5m bias ({$ltfBias}) contradicts the proposed {$action}.",
                $report
            );
        }

        // ── Hard block 1: 5m's OWN structure directly contradicts the trade ──
        if ($opposingStructure && !$structureTrigger) {
            return $this->wait(
                "5m structure contradicts this {$action} — a confirmed {$opposingDir} BOS/CHoCH exists on 5m "
                . "with nothing supporting the {$actionDir} side.",
                "Wait for 5m structure to actually confirm {$actionDir} (a {$actionDir} BOS/CHoCH), not just a "
                . "higher-timeframe label.",
                "WAIT: {$action} direction contradicted by confirmed 5m structure.",
                $report
            );
        }

        // ── Hard block 2: true structural conflict — 5m just flipped ────────
        if ($bosBull && $bosBear) {
            return $this->wait(
                '5m structure just flipped (both bullish and bearish BOS/CHoCH present in the recent window) — '
                . 'genuinely unresolved, not tradeable yet.',
                'Wait for 5m structure to resolve one direction before entering.',
                'WAIT: pattern/structure conflict remains unresolved.',
                $report
            );
        }

        // ── Hard block 3: strong, unresolved pattern conflict ───────────────
        // Quality over quantity — two high-confidence opposing patterns is a
        // real conflict regardless of how many weaker patterns sit on either
        // side. Only an actual structure trigger (BOS/CHoCH) resolves it;
        // "forming" patterns never independently authorize BUY/SELL.
        if ($hasStrongPatternConflict) {
            return $this->wait(
                'Strong opposing chart patterns (>=' . self::STRONG_PATTERN_CONFIDENCE . '% confidence on both '
                . 'sides) with no structure trigger resolving the conflict.',
                'Wait for a confirmed BOS/CHoCH to resolve which pattern is actually playing out.',
                'WAIT: pattern conflict remains unresolved.',
                $report
            );
        }

        // ── Hard block 4: insufficient room before opposing structure ──────
        if ($roomAtr !== null && $roomAtr < self::ROOM_HARD_BLOCK_ATR) {
            return $this->wait(
                "Nearest opposing structure is only {$roomAtr}x ATR away (< " . self::ROOM_HARD_BLOCK_ATR . "x) — "
                . 'not enough room to target before hitting it.',
                'Entry blocked: insufficient room before opposing structure.',
                'Entry blocked: insufficient room before opposing structure.',
                $report
            );
        }
        // Soft room block (0.5-1.0x ATR): waits unless momentum is
        // exceptionally strong AND a real structure trigger backs the trade
        // (approximating "the opposing structure itself is weak").
        if ($roomAtr !== null && $roomAtr < self::ROOM_SOFT_BLOCK_ATR
            && !($volumeTier === 'strong' && $structureTrigger)
        ) {
            return $this->wait(
                "Nearest opposing structure is only {$roomAtr}x ATR away (< " . self::ROOM_SOFT_BLOCK_ATR . "x) "
                . 'and momentum is not exceptionally strong enough to justify the tight room.',
                'Wait for either more room to target or materially stronger momentum (volume + confirmed structure).',
                'Entry blocked: insufficient room before opposing structure.',
                $report
            );
        }

        // ── Hard block 5: overextended — don't chase the move ───────────────
        if ($rsiExtended || $emaExtended) {
            return $this->wait(
                "Price is dangerously overextended for a {$actionDir} entry ("
                . ($rsiExtended ? 'RSI' : '') . ($rsiExtended && $emaExtended ? ' and ' : '') . ($emaExtended ? 'distance from EMA20' : '') . ' too far).',
                'Wait for a pullback/retest closer to EMA20/structure before entering.',
                "WAIT: {$actionDir} bias confirmed, but price is overextended — no valid entry location.",
                $report
            );
        }

        // ── Hard block 6: volume-or-momentum floor ──────────────────────────
        // NOT a pure volume floor — low volume alone never rejects a trade if
        // there's real displacement. But that displacement must be a genuine
        // confirmed STRUCTURE trigger (BOS/CHoCH) — a liquidity sweep alone is
        // not sufficient to bypass a low-volume read.
        if ($volumeRatio !== null && $volumeRatio < self::VOLUME_MOMENTUM_FLOOR && !$structureTrigger) {
            return $this->wait(
                "Volume is {$volumeRatio}x average (< " . self::VOLUME_MOMENTUM_FLOOR . "x) with no confirmed "
                . 'structure trigger (a liquidity sweep alone is not sufficient here).',
                'Wait for volume to pick up or a confirmed BOS/CHoCH.',
                "WAIT: {$actionDir} direction confirmed, but 5m entry trigger is not confirmed.",
                $report
            );
        }

        // ── Hard block 7: MTF confidence floors — entry validity, not just a
        // report label. "Alignment" (same direction) != "confirmation", and
        // confidence attached to an OPPOSING bias must not count toward the floor
        // at all (see $htfDirConf/$mtfDirConf/$ltfDirConf above).
        if ($htfDirConf < self::MTF_FLOOR_HTF || $mtfDirConf < self::MTF_FLOOR_MTF || $ltfDirConf < self::MTF_FLOOR_LTF) {
            $htfDesc = $htfBias === $actionDir ? "{$htfDirConf}%" : "{$htfConf}% {$htfBias} (opposes {$actionDir} — counts as 0%)";
            $mtfDesc = $mtfBias === $actionDir ? "{$mtfDirConf}%" : "{$mtfConf}% {$mtfBias} (opposes {$actionDir} — counts as 0%)";
            $ltfDesc = $ltfBias === $actionDir ? "{$ltfDirConf}%" : "{$ltfConf}% {$ltfBias} (opposes {$actionDir} — counts as 0%)";
            $anyContradicts = $htfBias !== $actionDir || $mtfBias !== $actionDir || $ltfBias !== $actionDir;
            $primaryReason = $anyContradicts
                ? 'WAIT: MTF directions conflict, and/or MTF confidence is too weak.'
                : 'WAIT: MTF directions agree, but MTF confidence is too weak.';
            return $this->wait(
                "MTF confidence too weak: 1h={$htfDesc} (need >=" . self::MTF_FLOOR_HTF . "% IN THE {$actionDir} DIRECTION), "
                . "15m={$mtfDesc} (need >=" . self::MTF_FLOOR_MTF . "%), "
                . "5m={$ltfDesc} (need >=" . self::MTF_FLOOR_LTF . "%). Same-direction labels alone "
                . "(WEAK ALIGNMENT) do not authorize a trade, and an opposing-direction reading never counts "
                . "toward the floor regardless of its own confidence.",
                'Wait for HTF/MTF/LTF confidence to each clear their floor IN THIS TRADE\'S DIRECTION, not just share a direction or read confidently in the opposite one.',
                $primaryReason,
                $report
            );
        }

        // ── Execution Gate ───────────────────────────────────────────────────
        // Final Confidence floor raised to 60% (was 55%) — a high score can no
        // longer squeak through the gate at 55-59%; that band folded into the
        // gate itself (no separate MARGINAL branch needed anymore).
        $triggerPresent = $structureTrigger || $liquidityTrigger;
        $gatePass = $triggerPresent
            && $executionScore >= self::GATE_MIN_SUBSCORE
            && $setupQuality   >= self::GATE_MIN_SUBSCORE
            && $finalConfidence >= self::GATE_MIN_FINAL_CONF
            && $rrValue >= self::GATE_MIN_RR;

        if (!$gatePass) {
            $missing = [];
            if (!$triggerPresent)                          $missing[] = 'a 5m BOS/CHoCH or liquidity sweep trigger';
            if ($executionScore < self::GATE_MIN_SUBSCORE)  $missing[] = "Execution Score >= " . self::GATE_MIN_SUBSCORE . " (currently {$executionScore})";
            if ($setupQuality < self::GATE_MIN_SUBSCORE)    $missing[] = "Setup Quality >= " . self::GATE_MIN_SUBSCORE . " (currently {$setupQuality})";
            if ($finalConfidence < self::GATE_MIN_FINAL_CONF) $missing[] = "Final Confidence >= " . self::GATE_MIN_FINAL_CONF . "% (currently {$finalConfidence}%)";
            if ($rrValue < self::GATE_MIN_RR)               $missing[] = "R:R >= 1:" . self::GATE_MIN_RR . " (currently 1:{$rrValue})";

            return $this->wait(
                'Execution Gate not cleared: missing ' . implode('; ', $missing) . '.',
                ucfirst($actionDir) . ' bias exists, but entry requires ' . implode(' and ', $missing) . '.',
                "WAIT: {$actionDir} direction confirmed, but 5m entry trigger is not confirmed.",
                $report
            );
        }

        // ── Grade ────────────────────────────────────────────────────────────
        $grade = $finalConfidence >= self::GRADE_A_MIN_FINAL
            && $executionScore >= self::GRADE_A_MIN_SUB
            && $setupQuality >= self::GRADE_A_MIN_SUB
            && $structureTrigger
            && $rrValue >= self::GRADE_A_MIN_RR
            ? 'A'
            : 'B'; // gate already enforces finalConfidence >= GRADE_B_MIN_FINAL (60), so anything past the gate is at least B

        $report['executable_signal'] = true;
        $report['grade']             = $grade;
        $report['hard_blocks']       = 'PASS';
        $report['final_gate']        = 'PASSED';

        return [
            'verdict'          => 'PROCEED',
            'reason'           => null,
            'final_confidence' => $finalConfidence,
            'report'           => $report,
        ];
    }

    private function wait(string $reason, string $nextTriggerNeeded, string $primaryReason, array $report): array
    {
        $report['final_gate']          = 'BLOCKED';
        $report['hard_blocks']         = 'FAIL';
        $report['next_trigger_needed'] = $nextTriggerNeeded;
        $report['primary_reason']      = $primaryReason;
        return [
            'verdict'          => 'WAIT',
            'reason'           => $reason,
            'final_confidence' => $report['execution_confidence'],
            'report'           => $report,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Component scores
    // ─────────────────────────────────────────────────────────────────────

    private function directionScore(
        string $actionDir,
        string $htfBias, int $htfConf,
        string $mtfBias, int $mtfConf,
        string $ltfBias, int $ltfConf
    ): int {
        $contribution = fn(string $bias, int $conf) => $bias === $actionDir ? $conf : 0;

        $score = 0.5 * $contribution($ltfBias, $ltfConf)
               + 0.3 * $contribution($mtfBias, $mtfConf)
               + 0.2 * $contribution($htfBias, $htfConf);

        return (int) round(max(0, min(100, $score)));
    }

    /**
     * @return array{direction:string, strength:string, status:string}
     */
    private function classifyMtfAlignment(
        string $htfBias, int $htfConf,
        string $mtfBias, int $mtfConf,
        string $ltfBias, int $ltfConf,
        string $actionDir,
        bool $hasEntryTrigger
    ): array {
        $allAgree = $htfBias === $mtfBias && $mtfBias === $ltfBias
            && in_array($htfBias, ['bullish', 'bearish'], true);

        $opposingDir = $actionDir === 'bullish' ? 'bearish' : 'bullish';
        $anyOpposes = $htfBias === $opposingDir || $mtfBias === $opposingDir || $ltfBias === $opposingDir;

        if ($allAgree) {
            $clearsFloors = $htfConf >= self::MTF_FLOOR_HTF && $mtfConf >= self::MTF_FLOOR_MTF && $ltfConf >= self::MTF_FLOOR_LTF;

            if ($clearsFloors && $hasEntryTrigger
                && $htfConf >= self::MTF_STRONG_HTF_MIN && $mtfConf >= self::MTF_STRONG_MTF_MIN && $ltfConf >= self::MTF_STRONG_LTF_MIN
            ) {
                $strength = 'STRONG';
            } elseif ($clearsFloors && $hasEntryTrigger) {
                $strength = 'FULL_CONFIRMATION';
            } else {
                $strength = 'WEAK_ALIGNMENT';
            }
            return ['direction' => strtoupper($htfBias), 'strength' => $strength, 'status' => 'ALIGNED'];
        }

        if ($anyOpposes) {
            return ['direction' => 'MIXED', 'strength' => 'N/A', 'status' => 'CONFLICT'];
        }

        return ['direction' => 'UNCLEAR', 'strength' => 'N/A', 'status' => 'UNCLEAR'];
    }

    private function setupQuality(array $taData, string $actionDir): int
    {
        $score = 60;

        // Ranging penalty softened 10→5 — market_regime.py's own confidence_modifier
        // (folded into overall_confidence/ltfConf upstream, and so already dragging
        // down directionScore here) already penalizes "ranging" once; this was a
        // second, independent penalty for the same underlying fact stacking on top
        // of the first instead of being the only place it was scored.
        $regime = $taData['market_regime']['regime'] ?? 'unknown';
        $score += match ($regime) {
            'expansion' => 15,
            'trending'  => 10,
            'ranging'   => -5,
            default     => 0,
        };

        $score += $this->patternConflictDelta($taData);

        if ($this->hasNearbyAlignedLiquidityZone($taData, $actionDir)) {
            $score += 10;
        }

        return (int) max(0, min(100, $score));
    }

    /**
     * LOW/MEDIUM/HIGH pattern-conflict tiering (0/-5/-10), keyed on whether a
     * pattern's trigger has actually broken (status confirmed_breakout/
     * confirmed_breakdown), not just raw confidence — mirrors
     * PreTradeFilterService::checkPatternAgreement()'s retiered logic. This is
     * the SOFTER scoring signal; hasUnresolvedStrongPatternConflict() below is
     * the separate, confidence-magnitude-based HARD block.
     */
    private function patternConflictDelta(array $taData): int
    {
        $bullConfirmed = false;
        $bearConfirmed = false;
        $bullPresent   = false;
        $bearPresent   = false;

        foreach ($taData['classical']['chart_patterns'] ?? [] as $p) {
            $dir = strtolower($p['direction'] ?? '');
            $status = $p['status'] ?? null;
            if ($status === 'invalidated') continue;

            $confirmed = in_array($status, ['confirmed_breakout', 'confirmed_breakdown'], true);
            if ($dir === 'bullish') { $bullPresent = true; $bullConfirmed = $bullConfirmed || $confirmed; }
            if ($dir === 'bearish') { $bearPresent = true; $bearConfirmed = $bearConfirmed || $confirmed; }
        }

        if (!$bullPresent || !$bearPresent) {
            return 0; // no opposing patterns at all — nothing to penalize
        }

        if ($bullConfirmed && $bearConfirmed) return -10; // HIGH
        if ($bullConfirmed xor $bearConfirmed) return -5;  // MEDIUM
        return 0; // LOW — both sides still just "forming", informational only
    }

    /**
     * Confidence-magnitude-based strong-conflict HARD block (spec: "1 bullish
     * pattern confidence 90 + 1 bearish confidence 85 = STRONG CONFLICT...
     * require actual structure confirmation... until confirmed, WAIT").
     * Independent of trigger status — a pattern being "forming" doesn't make
     * a genuinely high-confidence opposing read on the other side go away;
     * only a real structure trigger resolves which side is actually playing out.
     */
    private function hasUnresolvedStrongPatternConflict(array $taData, bool $structureTrigger): bool
    {
        $bullStrong = false;
        $bearStrong = false;

        $allPatterns = array_merge(
            $taData['classical']['chart_patterns'] ?? [],
            $taData['harmonic']['patterns'] ?? []
        );

        foreach ($allPatterns as $p) {
            if (($p['status'] ?? null) === 'invalidated') continue;
            $dir  = strtolower($p['direction'] ?? '');
            $conf = (int) ($p['confidence'] ?? 0);
            if ($conf < self::STRONG_PATTERN_CONFIDENCE) continue;
            if ($dir === 'bullish') $bullStrong = true;
            if ($dir === 'bearish') $bearStrong = true;
        }

        return $bullStrong && $bearStrong && !$structureTrigger;
    }

    private function executionScore(bool $structureTrigger, bool $liquidityTrigger, ?string $volumeTier): int
    {
        $score = 30;

        if ($structureTrigger) {
            $score += 35;
        }

        if ($liquidityTrigger && $structureTrigger) {
            $score += 20; // meaningful: sweep + structure confirmation together
        } elseif ($liquidityTrigger) {
            $score += 5; // informational only — sweep with no structure confirmation
        }

        $score += match ($volumeTier) {
            'strong'   => 15,
            'good'     => 10,
            'neutral'  => 0,
            'weak'     => -10,
            'very_low' => -15,
            default    => 0,
        };

        return (int) max(0, min(100, $score));
    }

    private function riskScore(float $rrValue): int
    {
        return match (true) {
            $rrValue >= 3.0 => 100,
            $rrValue >= 2.0 => 80,
            $rrValue >= 1.5 => 60,
            $rrValue >= 1.0 => 40,
            default         => 20,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Data helpers
    // ─────────────────────────────────────────────────────────────────────

    private function hasStructureEvent(array $taData, string $direction): bool
    {
        $structure = $taData['smc']['market_structure'] ?? [];
        foreach (array_merge($structure['bos'] ?? [], $structure['choch'] ?? []) as $s) {
            if (strtolower($s['direction'] ?? '') === $direction) {
                return true;
            }
        }
        return false;
    }

    private function hasLiquiditySweep(array $taData, string $direction): bool
    {
        $activeSweep = $taData['liquidity_sweeps']['active_sweep'] ?? null;
        return $activeSweep !== null && strtolower($activeSweep['direction'] ?? '') === $direction;
    }

    private function hasNearbyAlignedLiquidityZone(array $taData, string $actionDir): bool
    {
        $currentPrice = (float) ($taData['current_price'] ?? 0);
        if ($currentPrice <= 0) return false;

        $zones = $taData['smc']['liquidity_zones'] ?? [];
        $allZones = array_merge($zones['buy_side'] ?? [], $zones['sell_side'] ?? []);
        foreach ($allZones as $zone) {
            $zonePrice = (float) ($zone['price'] ?? 0);
            $zoneType  = strtolower($zone['type'] ?? '');
            if ($zonePrice <= 0) continue;
            $dist = abs($zonePrice - $currentPrice) / $currentPrice;
            if ($dist < 0.015
                && (($actionDir === 'bullish' && str_contains($zoneType, 'buy'))
                 || ($actionDir === 'bearish' && str_contains($zoneType, 'sell')))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Distance (in ATR multiples) from current price to the nearest opposing
     * FVG / Order Block / classical S-R level sitting on the wrong side of
     * price — an unfilled bearish FVG above price for a BUY, etc. Returns
     * null when there's no ATR to normalize against or no opposing level
     * found within the data (never treated as a block in that case).
     *
     * Two corrections vs. the original version, both aimed at the same bug
     * (measuring to a zone's midpoint without regard to where price actually
     * sits relative to the zone):
     *   - Inside-zone zones are SKIPPED, not measured. A zone straddling
     *     current price isn't a future obstacle — price is already trading
     *     inside it — and the old midpoint-vs-currentPrice comparison gave an
     *     essentially arbitrary in/out verdict depending on which half of the
     *     zone price happened to sit in, sometimes hard-blocking a trade over
     *     a zone it had already partly absorbed.
     *   - Zones genuinely ahead of price are measured to their FAR edge (the
     *     boundary price would have to fully clear), not the midpoint. The
     *     midpoint understates real room — it reports "blocked" a half-zone-
     *     width earlier than the zone is actually fully behind the move —
     *     which was killing valid setups on nothing more than a wide zone's
     *     near lip.
     *
     * Classical S/R levels are point levels (no zone), so they keep a direct
     * distance-to-level measurement, but only count when `strength` (touch
     * count from classical_analysis.py — how many times price has revisited
     * this level) is at least 2: a single-touch level is a noise pivot, not
     * real structure, and shouldn't be able to hard-block an otherwise-valid
     * entry on its own.
     */
    private function nearestOpposingStructureAtr(array $taData, string $actionDir): ?float
    {
        $currentPrice = (float) ($taData['current_price'] ?? 0);
        $atr          = (float) ($taData['classical']['atr']['current'] ?? 0);
        if ($currentPrice <= 0 || $atr <= 0) return null;

        $opposingType = $actionDir === 'bullish' ? 'bearish' : 'bullish';
        $distances    = [];

        // Zones (FVG/OB): skip if price is inside, else measure to the FAR edge.
        $measureZone = function (float $top, float $bottom) use ($currentPrice, $actionDir, &$distances): void {
            if ($top <= 0 || $bottom <= 0) return;
            [$lo, $hi] = $top >= $bottom ? [$bottom, $top] : [$top, $bottom];
            if ($currentPrice >= $lo && $currentPrice <= $hi) return; // inside — not a future obstacle

            $isWrongSide = $actionDir === 'bullish' ? $lo > $currentPrice : $hi < $currentPrice;
            if (!$isWrongSide) return;

            $farEdge = $actionDir === 'bullish' ? $hi : $lo;
            $distances[] = abs($farEdge - $currentPrice);
        };

        foreach ($taData['smc']['fair_value_gaps'] ?? [] as $fvg) {
            if (!empty($fvg['filled'])) continue;
            if (strtolower($fvg['type'] ?? '') !== $opposingType) continue;
            $measureZone((float) ($fvg['top'] ?? 0), (float) ($fvg['bottom'] ?? 0));
        }

        foreach ($taData['smc']['order_blocks'] ?? [] as $ob) {
            if (strtolower($ob['type'] ?? '') !== $opposingType) continue;
            $measureZone((float) ($ob['top'] ?? 0), (float) ($ob['bottom'] ?? 0));
        }

        // Classical S/R: point levels, direct distance — but only "real" (multi-touch) ones.
        $srKey = $actionDir === 'bullish' ? 'resistance' : 'support';
        $isWrongSide = fn(float $level) => $actionDir === 'bullish' ? $level > $currentPrice : $level < $currentPrice;
        foreach ($taData['classical']['support_resistance'][$srKey] ?? [] as $level) {
            $price    = (float) ($level['price'] ?? $level['level'] ?? 0);
            $strength = (int) ($level['strength'] ?? 0);
            if ($price > 0 && $strength >= 2 && $isWrongSide($price)) {
                $distances[] = abs($price - $currentPrice);
            }
        }

        if (empty($distances)) return null;

        return round(min($distances) / $atr, 3);
    }

    /**
     * "RSI alone must never CREATE a trade" (it's never a valid entry trigger)
     * — but it CAN independently block one when dangerously overextended.
     */
    private function isRsiOverextended(array $taData, string $actionDir): bool
    {
        $rsi = $taData['classical']['rsi']['current'] ?? null;
        if ($rsi === null) return false;
        if ($actionDir === 'bullish' && $rsi >= self::RSI_OVEREXTENDED_HIGH) return true;
        if ($actionDir === 'bearish' && $rsi <= self::RSI_OVEREXTENDED_LOW) return true;
        return false;
    }

    /**
     * "Don't chase an extended move" — price too far from EMA20 relative to
     * normal ATR-scale movement. (No VWAP field exists in the TA payload —
     * EMA20 is the extension reference used here.)
     */
    private function isEmaExtended(array $taData): bool
    {
        $ema20Pct = $taData['classical']['moving_averages']['ema20_pct'] ?? null;
        $atrPct   = $this->atrPct($taData);
        if ($ema20Pct === null || $atrPct === null || $atrPct <= 0) return false;

        $extensionMultiple = abs((float) $ema20Pct) / $atrPct;
        return $extensionMultiple > self::EMA20_EXTENSION_ATR_MULTIPLE;
    }

    private function atrPct(array $taData): ?float
    {
        $atr   = (float) ($taData['classical']['atr']['current'] ?? 0);
        $price = (float) ($taData['current_price'] ?? 0);
        if ($atr <= 0 || $price <= 0) return null;
        return ($atr / $price) * 100;
    }

    /**
     * @return array{0: ?float, 1: ?string} [volumeRatio, tier] — tier is null
     *         when volume data is unavailable (never treated as a penalty).
     */
    private function classifyVolume(array $taData): array
    {
        $ratio = $taData['market_regime']['details']['volume_ratio'] ?? null;
        if ($ratio === null) {
            return [null, null];
        }
        $ratio = (float) $ratio;

        $tier = match (true) {
            $ratio >= 1.5 => 'strong',
            $ratio >= 1.0 => 'good',
            $ratio >= 0.7 => 'neutral',
            $ratio >= 0.4 => 'weak',
            default       => 'very_low',
        };

        return [$ratio, $tier];
    }
}
