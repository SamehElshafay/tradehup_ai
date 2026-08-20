<?php

namespace Tests\Unit;

use App\Services\PreTradeFilterService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers PreTradeFilterService::checkPatternAgreement()'s retiered LOW/MEDIUM/
 * HIGH (0%/-5%/-10%) logic (scalping spec §6-7) — trigger-status-based, not
 * confidence-magnitude-based. Uses reflection since the method is private and
 * exercising it via the full run() pipeline would require an unrelated amount
 * of fixture data for the other eight layers it doesn't touch.
 */
class PreTradeFilterServicePatternTierTest extends TestCase
{
    private function invoke(array $taData): array
    {
        $service = new PreTradeFilterService();
        $method = new ReflectionMethod(PreTradeFilterService::class, 'checkPatternAgreement');
        $method->setAccessible(true);
        [$penalty, $warning, $log] = $method->invoke($service, $taData);
        return ['penalty' => $penalty, 'warning' => $warning, 'log' => $log];
    }

    public function test_low_tier_when_both_sides_still_forming(): void
    {
        $result = $this->invoke([
            'classical' => ['chart_patterns' => [
                ['name' => 'Double Bottom', 'direction' => 'bullish', 'confidence' => 80, 'status' => 'forming'],
                ['name' => 'Double Top', 'direction' => 'bearish', 'confidence' => 80, 'status' => 'forming'],
            ]],
            'harmonic' => ['patterns' => []],
        ]);

        $this->assertSame(20, $result['penalty']);
        $this->assertSame('conflict', $result['log']['verdict']);
    }

    public function test_medium_tier_when_one_side_confirmed(): void
    {
        $result = $this->invoke([
            'classical' => ['chart_patterns' => [
                ['name' => 'Double Bottom', 'direction' => 'bullish', 'confidence' => 80, 'status' => 'confirmed_breakout'],
                ['name' => 'Double Top', 'direction' => 'bearish', 'confidence' => 80, 'status' => 'forming'],
            ]],
            'harmonic' => ['patterns' => []],
        ]);

        $this->assertSame(20, $result['penalty']);
        $this->assertSame('conflict', $result['log']['verdict']);
    }

    public function test_high_tier_when_both_sides_confirmed(): void
    {
        $result = $this->invoke([
            'classical' => ['chart_patterns' => [
                ['name' => 'Double Bottom', 'direction' => 'bullish', 'confidence' => 80, 'status' => 'confirmed_breakout'],
                ['name' => 'Double Top', 'direction' => 'bearish', 'confidence' => 80, 'status' => 'confirmed_breakdown'],
            ]],
            'harmonic' => ['patterns' => []],
        ]);

        $this->assertSame(20, $result['penalty']);
        $this->assertSame('conflict', $result['log']['verdict']);
    }

    public function test_invalidated_patterns_are_excluded(): void
    {
        // The bearish pattern is tagged invalidated — it's excluded entirely,
        // leaving only the bullish side. With no opposing side left at all,
        // there's nothing to conflict with => no_conflict/none, not MEDIUM/HIGH.
        $result = $this->invoke([
            'classical' => ['chart_patterns' => [
                ['name' => 'Double Bottom', 'direction' => 'bullish', 'confidence' => 80, 'status' => 'confirmed_breakout'],
                ['name' => 'Double Top', 'direction' => 'bearish', 'confidence' => 80, 'status' => 'invalidated'],
            ]],
            'harmonic' => ['patterns' => []],
        ]);

        $this->assertSame(0, $result['penalty']);
        $this->assertSame('no_conflict', $result['log']['verdict']);
    }
}
