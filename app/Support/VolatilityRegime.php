<?php

namespace App\Support;

/**
 * Single source of truth for volatility-regime classification and its ATR
 * multipliers. Previously this logic was a private copy inside
 * AnalysisController while OpenRouterService's prompt text and
 * PreTradeFilterService's pre-AI gate each hardcoded their own fixed 1.5x
 * multiplier — meaning the "1.5x ATR" label shown to the AI/user could
 * disagree with the multiplier actually enforced downstream. All three now
 * call this class instead.
 */
class VolatilityRegime
{
    /**
     * 1m/5m ATR% naturally reads far lower than swing timeframes (typically
     * 0.05%-0.5% vs 1h's 0.5%-3%+). Classifying scalping timeframes against
     * the swing-timeframe bands below nearly always landed them in 'low',
     * which then handed out the tightest SL multiplier (1.0x ATR) to trades
     * that needed room for 5m noise — a direct contributor to the "hits
     * stop-loss" complaint. Scalping timeframes get their own bands/multipliers.
     */
    private const SCALPING_TIMEFRAMES = ['1m', '5m'];

    private static function isScalping(?string $timeframe): bool
    {
        return $timeframe !== null && in_array($timeframe, self::SCALPING_TIMEFRAMES, true);
    }

    public static function classify(float $atrPct, ?string $timeframe = null): string
    {
        if (self::isScalping($timeframe)) {
            if ($atrPct < 0.15) return 'low';
            if ($atrPct < 0.4) return 'medium';
            if ($atrPct < 1.0) return 'high';
            return 'very_high';
        }
        if ($atrPct < 0.5) return 'low';
        if ($atrPct < 1.5) return 'medium';
        if ($atrPct < 3.0) return 'high';
        return 'very_high';
    }

    /**
     * @return array{sl: float, tp_step: float}
     */
    public static function multipliers(string $regime, ?string $timeframe = null): array
    {
        if (self::isScalping($timeframe)) {
            return match ($regime) {
                'low'       => ['sl' => 2.0, 'tp_step' => 1.00],
                'medium'    => ['sl' => 2.0, 'tp_step' => 1.25],
                'high'      => ['sl' => 2.5, 'tp_step' => 1.50],
                'very_high' => ['sl' => 3.0, 'tp_step' => 2.00],
                default     => ['sl' => 2.0, 'tp_step' => 1.25],
            };
        }
        return match ($regime) {
            'low'       => ['sl' => 1.0, 'tp_step' => 1.00],
            'medium'    => ['sl' => 1.5, 'tp_step' => 1.25],
            'high'      => ['sl' => 2.0, 'tp_step' => 1.50],
            'very_high' => ['sl' => 2.5, 'tp_step' => 2.00],
            default     => ['sl' => 1.5, 'tp_step' => 1.25],
        };
    }

    /** Convenience: classify from raw ATR + price, then return the SL multiplier. */
    public static function slMultiplierFromAtr(float $atr, float $price, ?string $timeframe = null): float
    {
        if ($atr <= 0 || $price <= 0) {
            return self::multipliers('medium', $timeframe)['sl'];
        }
        $atrPct = ($atr / $price) * 100;
        return self::multipliers(self::classify($atrPct, $timeframe), $timeframe)['sl'];
    }

    // Structure-anchored SL is allowed to be WIDER than the bare regime minimum
    // (that's the whole point of "SL behind the nearest invalidation structure") —
    // but it must still be bounded, or a distant support/OB level can produce an
    // effectively unbounded stop. Explicit per-regime ceiling (not a multiplicative
    // factor on the minimum) — a multiplicative factor let very_high regime SL drift
    // up to 6.25x ATR (2.5min × 2.5factor), under which a real observed 4.83x SL
    // slipped through uncapped. These are hard numbers, independent of the minimum.
    private const MAX_SL_MULTIPLIER = [
        'low'       => 1.5,
        'medium'    => 2.0,
        'high'      => 2.5,
        'very_high' => 3.0,
    ];

    // Scalping (1m/5m) ceilings sit a full band above the swing ones since their
    // SL multiplier floor above (2.0-3.0x) is already higher than swing's (1.0-2.5x).
    private const SCALPING_MAX_SL_MULTIPLIER = [
        'low'       => 3.5,
        'medium'    => 3.5,
        'high'      => 4.0,
        'very_high' => 4.5,
    ];

    public static function maxSlMultiplier(string $regime, ?string $timeframe = null): float
    {
        if (self::isScalping($timeframe)) {
            return self::SCALPING_MAX_SL_MULTIPLIER[$regime] ?? self::SCALPING_MAX_SL_MULTIPLIER['medium'];
        }
        return self::MAX_SL_MULTIPLIER[$regime] ?? self::MAX_SL_MULTIPLIER['medium'];
    }

    /** Human-readable regime label with the actual numeric ATR% and threshold, so
     *  "Low volatility" never appears without the number that justifies it. */
    public static function describe(string $regime, float $atrPct, ?string $timeframe = null): string
    {
        $atrPctFmt = round($atrPct, 3);
        if (self::isScalping($timeframe)) {
            return match ($regime) {
                'low'       => "🟢 Low (ATR={$atrPctFmt}% < 0.15%)",
                'medium'    => "🟡 Medium (ATR={$atrPctFmt}%, 0.15%–0.4%)",
                'high'      => "🟠 High (ATR={$atrPctFmt}%, 0.4%–1.0%)",
                'very_high' => "🔴 Very High (ATR={$atrPctFmt}% ≥ 1.0%)",
                default     => "{$regime} (ATR={$atrPctFmt}%)",
            };
        }
        return match ($regime) {
            'low'       => "🟢 Low (ATR={$atrPctFmt}% < 0.5%)",
            'medium'    => "🟡 Medium (ATR={$atrPctFmt}%, 0.5%–1.5%)",
            'high'      => "🟠 High (ATR={$atrPctFmt}%, 1.5%–3.0%)",
            'very_high' => "🔴 Very High (ATR={$atrPctFmt}% ≥ 3.0%)",
            default     => "{$regime} (ATR={$atrPctFmt}%)",
        };
    }
}
