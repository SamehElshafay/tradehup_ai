"""
Taker Pressure Analysis — Spot OI Alternative
===============================================
Since Open Interest is only available for Futures contracts,
this module provides an equivalent directional-pressure signal
for Spot markets using Binance's taker_buy_base volume.

Logic mirrors the 4 classic OI states:
  ┌──────────────────┬──────────────┬────────────────────────────────────┐
  │ State            │ Price        │ Buy Ratio                          │
  ├──────────────────┼──────────────┼────────────────────────────────────┤
  │ Long Buildup     │ Rising       │ High (≥55%) — real buying demand   │
  │ Short Covering   │ Rising       │ Low  (≤45%) — shorts being closed  │
  │ Selling Pressure │ Falling      │ Low  (≤45%) — real selling         │
  │ Distribution     │ Falling      │ High (≥55%) — longs bailing out    │
  └──────────────────┴──────────────┴────────────────────────────────────┘

Additional output: trend_alignment — does the pressure type agree with
the overall_bias produced by bias_combiner?
"""

import pandas as pd

# ── Thresholds ──────────────────────────────────────────────────────────────
BUY_RATIO_HIGH = 55.0   # >= 55% → buyers dominating
BUY_RATIO_LOW  = 45.0   # <= 45% → sellers dominating
PRICE_MOVE_PCT = 0.001  # >= 0.1% net move over window = trending (not flat)


def analyze_taker_pressure(
    df: pd.DataFrame,
    overall_bias: str = "neutral",
    lookback: int = 20
) -> dict:
    """
    Classify current market pressure using taker buy ratios and price direction.

    Parameters
    ----------
    df           : OHLCV DataFrame with optional 'taker_buy_base' column
    overall_bias : overall_bias from bias_combiner (bullish / bearish / neutral)
    lookback     : number of candles to analyse

    Returns
    -------
    dict with pressure_type, buy_ratio, momentum_strength, trend_alignment, ...
    """
    if df is None or len(df) < 5:
        return _no_data_result()

    window = df.tail(lookback).copy()

    # ── Buy ratio ────────────────────────────────────────────────────────
    if "taker_buy_base" in window.columns:
        taker_buy = pd.to_numeric(window["taker_buy_base"], errors="coerce").fillna(0).sum()
        using_taker = True
    else:
        # Fallback: count bullish candles' volume as buy volume
        taker_buy = window.apply(
            lambda r: float(r["volume"]) if float(r["close"]) >= float(r["open"]) else 0.0,
            axis=1
        ).sum()
        using_taker = False

    total_volume = pd.to_numeric(window["volume"], errors="coerce").fillna(0).sum()
    buy_ratio = round(taker_buy / total_volume * 100, 2) if total_volume > 0 else 50.0

    # ── Price direction over the window ──────────────────────────────────
    price_start = float(window["close"].iloc[0])
    price_end   = float(window["close"].iloc[-1])
    net_move    = (price_end - price_start) / price_start if price_start > 0 else 0

    price_rising = net_move >= PRICE_MOVE_PCT
    price_falling = net_move <= -PRICE_MOVE_PCT

    # ── Classify pressure type ───────────────────────────────────────────
    buyers_dominant = buy_ratio >= BUY_RATIO_HIGH
    sellers_dominant = buy_ratio <= BUY_RATIO_LOW

    if price_rising and buyers_dominant:
        pressure_type = "Long Buildup"         # Strong bullish — new longs being added
        pressure_bias = "bullish"
    elif price_rising and sellers_dominant:
        pressure_type = "Short Covering"       # Shorts being squeezed out
        pressure_bias = "bullish"
    elif price_falling and sellers_dominant:
        pressure_type = "Selling Pressure"     # Real selling — bearish
        pressure_bias = "bearish"
    elif price_falling and buyers_dominant:
        pressure_type = "Distribution"         # Longs exiting at highs / smart money selling
        pressure_bias = "bearish"
    else:
        pressure_type = "Neutral / Sideways"   # No dominant directional pressure
        pressure_bias = "neutral"

    # ── Momentum strength ────────────────────────────────────────────────
    deviation = abs(buy_ratio - 50.0)
    if deviation >= 15:
        momentum_strength = "strong"
    elif deviation >= 8:
        momentum_strength = "moderate"
    else:
        momentum_strength = "weak"

    # ── Trend alignment with overall_bias ────────────────────────────────
    ob = (overall_bias or "neutral").lower()
    if ob == "neutral" or pressure_bias == "neutral":
        trend_alignment = "neutral"
    elif pressure_bias == ob:
        trend_alignment = "aligned"
    else:
        trend_alignment = "conflict"

    return {
        "pressure_type":      pressure_type,
        "pressure_bias":      pressure_bias,
        "buy_ratio":          buy_ratio,
        "net_price_move_pct": round(net_move * 100, 3),
        "momentum_strength":  momentum_strength,
        "trend_alignment":    trend_alignment,
        "lookback_candles":   len(window),
        "using_taker_data":   using_taker,
    }


def _no_data_result() -> dict:
    return {
        "pressure_type":      "no_data",
        "pressure_bias":      "neutral",
        "buy_ratio":          None,
        "net_price_move_pct": None,
        "momentum_strength":  "no_data",
        "trend_alignment":    "no_data",
        "lookback_candles":   0,
        "using_taker_data":   False,
    }
