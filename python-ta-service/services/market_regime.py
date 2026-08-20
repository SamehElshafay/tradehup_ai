"""
Market Regime Detection — Trending / Ranging / Expansion

Classifies the current market context into one of three regimes:

  TRENDING   — clear directional movement (HH/HL or LH/LL structure)
               confidence_modifier: +5%
               threshold_suggestion: 0 (no change)

  RANGING    — sideways consolidation (no clear trend, Bollinger squeeze)
               confidence_modifier: -8 (ranging markets produce frequent false
               signals — penalized more heavily than a neutral/unknown regime,
               but not stacked as hard as -15 once this is the only place the
               "ranging" fact is scored — see ScalpingMtfGate's own softened
               setup-quality penalty)
               threshold_suggestion: -5 (lower gate at range extremes only)

  EXPANSION  — breakout / strong momentum (Bollinger squeeze + volume spike + BOS)
               confidence_modifier: +10%
               threshold_suggestion: -5 (momentous move, act faster)

Why does Regime matter?

  A Trending market is statistically the most reliable environment to trade.
  A Ranging market produces frequent false signals — most "breakouts" fail.
  An Expansion is the highest-reward regime, but also the fastest-moving;
  waiting for the full standard confidence gate may cause missing the move.

The regime modifier adjusts the PreTradeFilterService threshold dynamically
on top of the ATR-based adjustment from Phase 1.

Implementation notes:
  - BB Squeeze: current BB width < 40th percentile of the last 50 widths.
    This uses raw OHLCV data (not the single snapshot in classical output)
    to compute the historical distribution of widths.
  - MA Slope: derived from classical moving_averages.trend label.
  - Volume Spike: current volume vs 20-period average.
  - BOS: taken from SMC market_structure to avoid re-computation.
"""

import pandas as pd
import numpy as np


# ── Thresholds ────────────────────────────────────────────────────────────────
BB_SQUEEZE_PERCENTILE = 40    # BB width below this percentile → squeeze
VOLUME_SPIKE_RATIO    = 1.8   # Current volume vs avg that counts as "spike" —
                               # lowered from 2.5 now that binance_fetcher.py
                               # drops the still-forming candle (see P0-1):
                               # volume_ratio used to read a median ~0.36
                               # against closed-candle-only data because a
                               # partial candle's volume was compared to full
                               # ones, so 2.5 was tuned against corrupted data
                               # and almost never fired on real closed bars.
BB_WINDOW             = 20    # Candles for rolling BB calculation
ATR_TRENDING_MIN      = 0.3   # ATR% minimum to confirm trending (swing timeframes)
ATR_TRENDING_MIN_SCALPING = 0.12  # 5m ATR% reads far lower than swing TFs —
                                   # 0.3% on 5m was nearly unreachable, so almost
                                   # every 5m read fell through to ranging (-15%)
                                   # regardless of whether a real trend was present.
SCALPING_TIMEFRAMES = ("1m", "5m")


def detect_market_regime(df: pd.DataFrame, classical: dict, smc: dict, timeframe: str | None = None) -> dict:
    """
    Main entry point. Returns regime classification with modifiers.
    """
    if df is None or len(df) < BB_WINDOW + 5:
        return _unknown_regime("Insufficient data for regime detection")

    closes  = df["close"].values
    volumes = df["volume"].values
    n       = len(closes)

    current_price = float(closes[-1])

    # ── Bollinger Band squeeze detection ──────────────────────────────────────
    bb_squeeze, bb_width, bb_widths_hist = _detect_bb_squeeze(df)

    # ── Moving Average slope (from classical output) ───────────────────────────
    ma_trend  = classical.get("moving_averages", {}).get("trend", "neutral")
    ma_slope  = _classify_ma_slope(ma_trend)

    # ── Volume spike ──────────────────────────────────────────────────────────
    # Median, not mean: volume is right-skewed (a few large candles pull the
    # mean well above what a "typical" candle looks like), so dividing by the
    # mean systematically understated volume_ratio for most candles — after
    # P0-1 fixed the corrupted baseline, live data showed a true median ratio
    # of ~0.69 while ScalpingMtfGate's own 'neutral' volume tier boundary is
    # 0.7, so the typical candle was landing in 'weak' by construction. Median
    # makes 1.0 actually mean "typical candle" and needs no downstream tier
    # threshold changes to become correctly calibrated.
    lookback_vol = min(20, n)
    avg_vol      = float(np.median(volumes[-lookback_vol:]))
    recent_vol   = float(volumes[-1])
    volume_ratio = round(recent_vol / avg_vol, 2) if avg_vol > 0 else 0
    volume_spike = volume_ratio >= VOLUME_SPIKE_RATIO

    # ── ATR % ─────────────────────────────────────────────────────────────────
    atr     = classical.get("atr", {}).get("current", 0)
    atr_pct = round((atr / current_price * 100), 3) if atr and current_price else 0
    atr_trending_min = ATR_TRENDING_MIN_SCALPING if timeframe in SCALPING_TIMEFRAMES else ATR_TRENDING_MIN

    # ── Recent BOS / CHoCH ───────────────────────────────────────────────────
    structure    = smc.get("market_structure", {})
    recent_bos   = len(structure.get("bos", [])) > 0
    recent_choch = len(structure.get("choch", [])) > 0

    # ── Classify ──────────────────────────────────────────────────────────────
    details = {
        "bb_squeeze"   : bb_squeeze,
        "bb_width_pct" : round(bb_width, 3),
        "recent_bos"   : recent_bos,
        "recent_choch" : recent_choch,
        "volume_spike" : volume_spike,
        "volume_ratio" : volume_ratio,
        "ma_slope"     : ma_slope,
        "atr_pct"      : atr_pct,
    }

    # EXPANSION: squeeze breaking out with momentum + structure — any 2 of the
    # 3 signals (squeeze, volume spike, BOS/CHoCH), not all 3. Requiring all
    # three made EXPANSION nearly unreachable (a real breakout often clears
    # the squeeze on the very candle it spikes volume/breaks structure, so by
    # the time all three read true simultaneously the move is already over).
    expansion_signals = sum([bb_squeeze, volume_spike, (recent_bos or recent_choch)])
    if expansion_signals >= 2:
        return {
            "regime"              : "expansion",
            "regime_label"        : "🚀 Expansion / Breakout",
            "confidence_modifier" : 10,
            "threshold_suggestion": -5,
            "description"         : (
                "Bollinger Squeeze breaking out with volume spike and/or structure break — "
                "high-probability momentum move in progress"
            ),
            "details": details,
        }

    # TRENDING: clear directional MA slope + at least moderate ATR
    if ma_slope in ["upward", "downward"] and not bb_squeeze and atr_pct >= atr_trending_min:
        return {
            "regime"              : "trending",
            "regime_label"        : "📈 Trending Market" if ma_slope == "upward" else "📉 Trending Market (Bearish)",
            "confidence_modifier" : 5,
            "threshold_suggestion": 0,
            "description"         : (
                f"Clear {'bullish' if ma_slope == 'upward' else 'bearish'} trend — "
                "follow the momentum; counter-trend setups are high-risk"
            ),
            "details": details,
        }

    # RANGING: everything else. Modifier softened from -15 to -8 — -15 stacked
    # with the other regime-aware penalties (setup quality, pre-trade filter)
    # was over-penalizing ranging reads that still had a valid range-extreme
    # setup, compounding into an effective double/triple penalty for the same
    # "ranging" fact scored independently in multiple places.
    return {
        "regime"              : "ranging",
        "regime_label"        : "↔️ Ranging / Consolidation",
        "confidence_modifier" : -8,
        "threshold_suggestion": -5,
        "description"         : (
            "Market consolidating — most breakouts will fail; "
            "only trade confirmed range extremes (near S/R + OB/FVG) or wait for expansion"
        ),
        "details": details,
    }


# ── Helpers ───────────────────────────────────────────────────────────────────

def _detect_bb_squeeze(df: pd.DataFrame) -> tuple:
    """
    Compute rolling BB width over the last 50 periods and compare current width
    to the 40th percentile. Returns (is_squeeze, current_width, historical_widths).
    """
    closes = df["close"]
    window = BB_WINDOW

    if len(closes) < window + 5:
        return False, 0.0, []

    rolling_mean = closes.rolling(window).mean()
    rolling_std  = closes.rolling(window).std()

    # BB width = (upper - lower) / middle = (4 * std) / mean expressed as %
    widths = []
    for i in range(window, len(closes)):
        mean_val = rolling_mean.iloc[i]
        std_val  = rolling_std.iloc[i]
        if mean_val and mean_val > 0:
            w = (std_val * 4 / mean_val) * 100
            widths.append(float(w))

    if not widths:
        return False, 0.0, []

    current_width = widths[-1]
    percentile_40 = float(np.percentile(widths, BB_SQUEEZE_PERCENTILE))

    is_squeeze = current_width < percentile_40

    return is_squeeze, current_width, widths


def _classify_ma_slope(trend: str) -> str:
    """Map classical MA trend label → upward / downward / flat."""
    trend = (trend or "").lower()
    if "up" in trend:
        return "upward"
    if "down" in trend:
        return "downward"
    return "flat"


def _unknown_regime(reason: str) -> dict:
    return {
        "regime"              : "unknown",
        "regime_label"        : "❓ Unknown",
        "confidence_modifier" : 0,
        "threshold_suggestion": 0,
        "description"         : reason,
        "details"             : {},
    }
