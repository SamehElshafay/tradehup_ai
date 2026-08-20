<?php

namespace Tests\Unit;

use App\Services\ScalpingMtfGate;
use PHPUnit\Framework\TestCase;

class ScalpingMtfGateTest extends TestCase
{
    private ScalpingMtfGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ScalpingMtfGate();
    }

    /**
     * Builds a minimal $taData fixture: the top level IS the LTF/5m execution
     * payload (overall_bias/confidence/smc/liquidity_sweeps/market_regime),
     * exactly like AnalysisController's real $taData, with htf_data (1h) and
     * mtf_data (15m) injected the same way MTF mode does.
     */
    private function fixture(array $opts): array
    {
        $bosBull = $opts['bos_bull'] ?? false;
        $bosBear = $opts['bos_bear'] ?? false;
        $structure = ['bos' => [], 'choch' => []];
        if ($bosBull) $structure['choch'][] = ['direction' => 'bullish'];
        if ($bosBear) $structure['bos'][]   = ['direction' => 'bearish'];

        $sweepDir = $opts['sweep_direction'] ?? null;
        $liquiditySweeps = ['active_sweep' => $sweepDir ? ['direction' => $sweepDir] : null];

        $zones = ['buy_side' => [], 'sell_side' => []];
        if (!empty($opts['nearby_zone_aligned'])) {
            $isBull = ($opts['action'] ?? 'BUY') === 'BUY';
            $zones[$isBull ? 'buy_side' : 'sell_side'][] = [
                'price' => 100.5, 'type' => $isBull ? 'buy_side' : 'sell_side',
            ];
        }

        return [
            'symbol'             => 'TESTUSDT',
            'current_price'      => 100.0,
            'overall_bias'       => $opts['ltf_bias'],
            'overall_confidence' => $opts['ltf_conf'],
            'classical'          => [
                'chart_patterns'      => $opts['chart_patterns'] ?? [],
                'atr'                 => ['current' => $opts['atr'] ?? 0],
                'rsi'                 => ['current' => $opts['rsi'] ?? null],
                'moving_averages'     => ['ema20_pct' => $opts['ema20_pct'] ?? null],
                'support_resistance'  => $opts['support_resistance'] ?? ['support' => [], 'resistance' => []],
            ],
            'harmonic'           => ['patterns' => $opts['harmonic_patterns'] ?? []],
            'smc'                => [
                'market_structure'  => $structure,
                'liquidity_zones'   => $zones,
                'fair_value_gaps'   => $opts['fair_value_gaps'] ?? [],
                'order_blocks'      => $opts['order_blocks'] ?? [],
            ],
            'liquidity_sweeps'   => $liquiditySweeps,
            'market_regime'      => [
                'regime'  => $opts['regime'] ?? 'unknown',
                'details' => ['volume_ratio' => $opts['volume_ratio'] ?? null],
            ],
            'htf_data' => ['overall_bias' => $opts['htf_bias'], 'overall_confidence' => $opts['htf_conf']],
            'mtf_data' => ['overall_bias' => $opts['mtf_bias'], 'overall_confidence' => $opts['mtf_conf']],
        ];
    }

    /**
     * A real 5m setup with MTF numbers that CLEAR the hard floors (1h>=50,
     * 15m>=50, 5m>=60) plus a confirmed bullish BOS + aligned liquidity sweep
     * + trending regime + nearby aligned zone + good volume should reach
     * Grade A — proving a genuine setup isn't crushed by the weighted
     * architecture once MTF confirmation is actually adequate.
     */
    public function test_real_5m_setup_with_confirmed_mtf_reaches_grade_a(): void
    {
        $taData = $this->fixture([
            'action' => 'BUY',
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'regime' => 'trending',
            'volume_ratio' => 1.2,
            'nearby_zone_aligned' => true,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('PROCEED', $result['verdict']);
        $this->assertSame('A', $result['report']['grade']);
        $this->assertGreaterThanOrEqual(70, $result['final_confidence']);
        $this->assertTrue($result['report']['executable_signal']);
    }

    /**
     * The PREVIOUS round's exact "weak MTF still proceeds" example (1h Bull
     * 32% / 15m Bull 26% / 5m Bull 15%, even with a confirmed bullish BOS +
     * sweep) must now WAIT — this is the intentional behavior change from
     * this round's spec: MTF ALIGNMENT != MTF CONFIRMATION, and the new hard
     * floors (1h>=50%, 15m>=50%, 5m>=60%) block it regardless of how clean
     * the 5m trigger is.
     */
    public function test_weak_mtf_confidence_now_hard_blocks_despite_real_5m_trigger(): void
    {
        $taData = $this->fixture([
            'action' => 'BUY',
            'htf_bias' => 'bullish', 'htf_conf' => 32,
            'mtf_bias' => 'bullish', 'mtf_conf' => 26,
            'ltf_bias' => 'bullish', 'ltf_conf' => 15,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'regime' => 'trending',
            'volume_ratio' => 1.2,
            'nearby_zone_aligned' => true,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('MTF confidence too weak', $result['reason']);
        $this->assertStringContainsString('MTF directions agree, but MTF confidence is too weak', $result['report']['primary_reason']);
    }

    /**
     * Spec's core rule: never enter simply because 1h+15m+5m share a label.
     * All three strongly bullish, but 5m has NO structure trigger, NO
     * liquidity trigger, and thin volume — must WAIT despite the "alignment".
     */
    public function test_aligned_labels_alone_do_not_produce_buy(): void
    {
        $taData = $this->fixture([
            'action' => 'BUY',
            'htf_bias' => 'bullish', 'htf_conf' => 70,
            'mtf_bias' => 'bullish', 'mtf_conf' => 70,
            'ltf_bias' => 'bullish', 'ltf_conf' => 70,
            'regime' => 'ranging',
            'volume_ratio' => 0.3,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 1.5);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertNotNull($result['report']['next_trigger_needed']);
    }

    /** Basic consistency backstop: 5m bias itself must match the proposed action. */
    public function test_5m_bias_mismatch_hard_blocks(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 70,
            'mtf_bias' => 'bullish', 'mtf_conf' => 70,
            'ltf_bias' => 'bearish', 'ltf_conf' => 70,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('cannot support a BUY', $result['reason']);
    }

    /**
     * Hard block: 5m's own structure contradicts the action even though the
     * 5m overall_bias label itself agrees with it (structure and the
     * indicator-driven bias label can genuinely disagree in real data).
     */
    public function test_hard_block_when_5m_structure_contradicts_action(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 70,
            'mtf_bias' => 'bullish', 'mtf_conf' => 70,
            'ltf_bias' => 'bullish', 'ltf_conf' => 70,
            'bos_bear' => true,
            'volume_ratio' => 1.0,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('contradicts', $result['reason']);
    }

    /** True structural conflict: both bullish and bearish BOS/CHoCH present on 5m. */
    public function test_true_structural_conflict_waits(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 70,
            'mtf_bias' => 'bullish', 'mtf_conf' => 70,
            'ltf_bias' => 'bullish', 'ltf_conf' => 70,
            'bos_bull' => true,
            'bos_bear' => true,
            'volume_ratio' => 1.0,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('flipped', $result['reason']);
    }

    /** Meaningful (sweep + structure) sweep must score materially higher Execution than sweep alone. */
    public function test_meaningful_sweep_scores_higher_than_informational_sweep(): void
    {
        $meaningful = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 60,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'volume_ratio' => 0.8, // neutral
        ]);
        $informationalOnly = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 60,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'sweep_direction' => 'bullish', // no structure trigger this time
            'volume_ratio' => 0.8,
        ]);

        $rMeaningful = $this->gate->evaluate('BUY', $meaningful, 2.0);
        $rInfo       = $this->gate->evaluate('BUY', $informationalOnly, 2.0);

        $this->assertSame(85, $rMeaningful['report']['execution_score']); // 30 + 35 (structure) + 20 (meaningful) + 0 (neutral vol)
        $this->assertSame(35, $rInfo['report']['execution_score']);       // 30 + 5 (informational sweep only) + 0
        $this->assertGreaterThan($rInfo['report']['execution_score'], $rMeaningful['report']['execution_score']);
    }

    /** Grade B (GOOD SCALP): Final Confidence 60-69, thresholds met but not A's stricter bar. */
    public function test_grade_b_good_scalp(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 60,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'regime' => 'trending',
            'volume_ratio' => 1.2, // good
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 1.5);

        $this->assertSame('PROCEED', $result['verdict']);
        $this->assertSame('B', $result['report']['grade']);
        $this->assertGreaterThanOrEqual(60, $result['final_confidence']);
        $this->assertLessThan(70, $result['final_confidence']);
    }

    /**
     * Final Confidence floor is 60% (raised from 55%) — a score of 57% now
     * fails the Execution Gate directly. The old separate "MARGINAL band"
     * WAIT branch was removed since it's redundant with this raised floor.
     */
    public function test_below_60_percent_final_confidence_waits(): void
    {
        // MTF numbers deliberately sit exactly AT the hard floors (50/50/60)
        // so the gate's Final Confidence check is reached, not the MTF floor block.
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 50,
            'mtf_bias' => 'bullish', 'mtf_conf' => 50,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'volume_ratio' => 0.55, // weak tier
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 1.5);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertGreaterThanOrEqual(55, $result['final_confidence']);
        $this->assertLessThan(60, $result['final_confidence']);
        $this->assertStringContainsString('Final Confidence >= 60%', $result['reason']);
    }

    /** MTF alignment classification: all agree + all above the STRONG thresholds. */
    public function test_mtf_strong_alignment_classification(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 65,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('STRONG', $result['report']['mtf_strength']);
        $this->assertSame('ALIGNED', $result['report']['mtf_status']);
    }

    /** MTF alignment classification: all agree but minimum confidence is weak (< 40%). */
    public function test_mtf_weak_alignment_classification(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 32,
            'mtf_bias' => 'bullish', 'mtf_conf' => 26,
            'ltf_bias' => 'bullish', 'ltf_conf' => 15,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'volume_ratio' => 1.0,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WEAK_ALIGNMENT', $result['report']['mtf_strength']);
        // Direction is still reported as bullish (that's what all 3 agree on) —
        // weak just means the strength label, not that direction is hidden.
        $this->assertSame('BULLISH', $result['report']['mtf_direction']);
    }

    /** Pattern conflict tiers (spec §6-7): LOW (0%) when neither side's trigger has broken. */
    public function test_pattern_conflict_low_when_both_forming(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 60,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'volume_ratio' => 0.8,
            'chart_patterns' => [
                ['direction' => 'bullish', 'confidence' => 80, 'status' => 'forming'],
                ['direction' => 'bearish', 'confidence' => 80, 'status' => 'forming'],
            ],
        ]);
        $baseline = $taData;
        $baseline['classical']['chart_patterns'] = [];

        $withPatterns = $this->gate->evaluate('BUY', $taData, 1.5);
        $withoutPatterns = $this->gate->evaluate('BUY', $baseline, 1.5);

        // LOW impact = 0 penalty — setup quality must be identical either way.
        $this->assertSame($withoutPatterns['report']['setup_quality'], $withPatterns['report']['setup_quality']);
    }

    /** Pattern conflict tiers: HIGH (-10%) when both sides have a broken trigger. */
    public function test_pattern_conflict_high_when_both_confirmed(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 60,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 60,
            'bos_bull' => true,
            'volume_ratio' => 0.8,
            'chart_patterns' => [
                ['direction' => 'bullish', 'confidence' => 80, 'status' => 'confirmed_breakout'],
                ['direction' => 'bearish', 'confidence' => 80, 'status' => 'confirmed_breakdown'],
            ],
        ]);
        $baseline = $taData;
        $baseline['classical']['chart_patterns'] = [];

        $withPatterns = $this->gate->evaluate('BUY', $taData, 1.5);
        $withoutPatterns = $this->gate->evaluate('BUY', $baseline, 1.5);

        $this->assertSame($withoutPatterns['report']['setup_quality'] - 10, $withPatterns['report']['setup_quality']);
    }

    /** Room hard block: opposing FVG's far edge only 0.35x ATR away (< 0.5x) must block outright. */
    public function test_room_hard_block_under_half_atr(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
            'atr' => 2.0,
            'fair_value_gaps' => [
                // Room is measured to the FAR edge (top=100.7), not the midpoint —
                // price has to clear the whole zone, not just reach its middle.
                // far edge dist=0.7, 0.7/2.0=0.35x ATR
                ['type' => 'bearish', 'filled' => false, 'top' => 100.7, 'bottom' => 100.5],
            ],
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertSame('Entry blocked: insufficient room before opposing structure.', $result['report']['primary_reason']);
        $this->assertEqualsWithDelta(0.35, $result['report']['nearest_opposing_structure_atr'], 0.001);
    }

    /** Room soft block: opposing structure's far edge 0.75x ATR away (0.5-1.0x) waits without the momentum exception. */
    public function test_room_soft_block_without_exception(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0, // 'good', not 'strong' — no exception
            'atr' => 2.0,
            'fair_value_gaps' => [
                // far edge (top=101.5) dist=1.5, 1.5/2.0=0.75x ATR
                ['type' => 'bearish', 'filled' => false, 'top' => 101.5, 'bottom' => 101.3],
            ],
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertEqualsWithDelta(0.75, $result['report']['nearest_opposing_structure_atr'], 0.001);
    }

    /** Room soft-block exception: same 0.7x ATR room, but strong volume + confirmed structure proceeds. */
    public function test_room_soft_block_exception_with_strong_momentum(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 65,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'regime' => 'trending',
            'volume_ratio' => 1.6, // 'strong' — qualifies for the exception
            'atr' => 2.0,
            'fair_value_gaps' => [
                ['type' => 'bearish', 'filled' => false, 'top' => 101.5, 'bottom' => 101.3], // 0.7x ATR
            ],
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('PROCEED', $result['verdict']);
    }

    /** Overextension hard block: RSI >= 80 blocks a BUY regardless of everything else. */
    public function test_overextension_rsi_hard_blocks_buy(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
            'rsi' => 85,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('overextended', $result['reason']);
    }

    /** Volume-or-momentum hard block: volume < 0.5x AND no structure/liquidity trigger at all. */
    public function test_volume_momentum_hard_block(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'volume_ratio' => 0.3,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('entry trigger is not confirmed', $result['report']['primary_reason']);
    }

    /** Strong pattern conflict (>=80% both sides) with NO structure resolving it must hard-block. */
    public function test_strong_pattern_conflict_unresolved_hard_blocks(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'volume_ratio' => 1.0,
            'chart_patterns' => [
                ['direction' => 'bullish', 'confidence' => 90, 'status' => 'forming'],
                ['direction' => 'bearish', 'confidence' => 85, 'status' => 'forming'],
            ],
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('pattern conflict remains unresolved', $result['report']['primary_reason']);
    }

    /** Same strong pattern conflict, but a confirmed structure trigger resolves it — no hard block. */
    public function test_strong_pattern_conflict_resolved_by_structure_proceeds(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 65,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'regime' => 'trending',
            'volume_ratio' => 1.2,
            'chart_patterns' => [
                ['direction' => 'bullish', 'confidence' => 90, 'status' => 'forming'],
                ['direction' => 'bearish', 'confidence' => 85, 'status' => 'forming'],
            ],
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('PROCEED', $result['verdict']);
    }

    /**
     * Tightened volume-bypass rule: a liquidity sweep ALONE (no structure
     * trigger) is no longer sufficient to bypass low volume — only a
     * confirmed structure trigger (BOS/CHoCH) does.
     */
    public function test_volume_bypass_requires_structure_not_just_sweep(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'sweep_direction' => 'bullish', // liquidity trigger present, but NO structure trigger
            'volume_ratio' => 0.3,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('liquidity sweep alone is not sufficient', $result['reason']);
    }

    /** Data validity hard block: missing/invalid current_price. */
    public function test_data_validity_hard_block(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
        ]);
        $taData['current_price'] = 0;

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('Data consistency error', $result['report']['primary_reason']);
    }

    /** Hard risk / volatility gate: ATR% beyond the extreme threshold blocks. */
    public function test_extreme_volatility_hard_blocks(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
            'atr' => 10.0, // current_price=100 -> ATR% = 10% > 8% extreme threshold
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertStringContainsString('extreme volatility', $result['report']['primary_reason']);
    }

    /** Structured validation report — spot-check a clean PROCEED case reports all PASS/VALID. */
    public function test_validation_report_all_pass_on_clean_proceed(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 65,
            'mtf_bias' => 'bullish', 'mtf_conf' => 60,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'sweep_direction' => 'bullish',
            'regime' => 'trending',
            'volume_ratio' => 1.2,
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('PROCEED', $result['verdict']);
        $v = $result['report']['validation'];
        $this->assertSame('VALID', $v['entry_setup']);
        $this->assertSame('PASS', $v['ltf_confirmation']);
        $this->assertSame('PASS', $v['mtf_confirmation']);
        $this->assertSame('NONE', $v['pattern_conflict']);
        $this->assertSame('CLEAR', $v['opposing_structure']);
        $this->assertSame('PASS', $v['extension_filter']);
        $this->assertSame('PASS', $v['volume_filter']);
        $this->assertSame('PASS', $v['rsi_extension']);
        $this->assertSame('PASS', $v['rr_filter']);
        $this->assertSame('PASS', $v['volatility_gate']);
        $this->assertSame('PASS', $v['data_consistency']);
        $this->assertSame('PASS', $v['final_scalping_gate']);
    }

    /** Structured validation report — RSI extension specifically reports FAIL when overextended. */
    public function test_validation_report_flags_rsi_extension(): void
    {
        $taData = $this->fixture([
            'htf_bias' => 'bullish', 'htf_conf' => 55,
            'mtf_bias' => 'bullish', 'mtf_conf' => 55,
            'ltf_bias' => 'bullish', 'ltf_conf' => 65,
            'bos_bull' => true,
            'volume_ratio' => 1.0,
            'rsi' => 78, // >= 75 threshold
        ]);

        $result = $this->gate->evaluate('BUY', $taData, 2.0);

        $this->assertSame('WAIT', $result['verdict']);
        $this->assertSame('FAIL', $result['report']['validation']['rsi_extension']);
        $this->assertSame('FAIL', $result['report']['validation']['final_scalping_gate']);
    }
}
