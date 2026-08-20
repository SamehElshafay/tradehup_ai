"""
CVD / Order Flow Analysis — OHLCV-based approximation
=======================================================
Computes Cumulative Volume Delta (CVD) from candlestick data.

Method: Binance OHLCV includes taker_buy_base (buy-initiated volume).
  candle_delta = taker_buy_base - (volume - taker_buy_base)
               = 2 * taker_buy_base - volume

This is the standard "candle delta" approximation used in
order-flow analysis when tick data is unavailable.

Outputs:
  • cvd_cumulative      — raw sum of deltas over the lookback window
  • cvd_trend           — bullish / bearish / neutral
  • buy_pressure_pct    — avg % of volume that is buy-initiated
  • divergence          — none / bullish_divergence / bearish_divergence
  • momentum_strength   — strong / moderate / weak
"""

import pandas as pd
import numpy as np

# ── Thresholds ──────────────────────────────────────────────────────────────
BUY_PRESSURE_BULLISH = 55.0   # >= 55% avg buy volume → bullish pressure
BUY_PRESSURE_BEARISH = 45.0   # <= 45% → bearish pressure
CVD_STRONG_RATIO     = 0.3    # CVD / total_volume > 30% → strong signal
CVD_MODERATE_RATIO   = 0.1    # > 10% → moderate


def calculate_cvd(df: pd.DataFrame, lookback: int = 20) -> dict:
    """
    Compute CVD metrics from OHLCV DataFrame.

    Requires 'taker_buy_base' column from Binance klines.
    Falls back to a candle-body approximation (close > open = buy candle)
    if taker_buy_base is missing — less accurate but always available.

    Parameters
    ----------
    df       : OHLCV DataFrame (must have 'volume', optionally 'taker_buy_base')
    lookback : number of candles to analyse (default 20 — ~100 min on 5m)

    Returns
    -------
    dict with CVD metrics
    """
    if df is None or len(df) < 2:
        return _no_data_result()

    window = df.tail(lookback).copy()

    # ── Delta per candle ─────────────────────────────────────────────────
    if "taker_buy_base" in window.columns:
        window["taker_buy"] = pd.to_numeric(window["taker_buy_base"], errors="coerce").fillna(0)
    else:
        # Fallback: if candle is bullish, treat full volume as buy-initiated
        window["taker_buy"] = window.apply(
            lambda r: float(r["volume"]) if float(r["close"]) >= float(r["open"]) else 0.0,
            axis=1
        )

    window["volume_f"] = pd.to_numeric(window["volume"], errors="coerce").fillna(0)
    window["delta"] = window["taker_buy"] * 2 - window["volume_f"]  # buy - sell

    # ── CVD cumulative ───────────────────────────────────────────────────
    cvd_cumulative = float(window["delta"].sum())
    total_volume   = float(window["volume_f"].sum())

    # ── Buy pressure % ───────────────────────────────────────────────────
    buy_vols = window["taker_buy"].sum()
    buy_pressure_pct = round((buy_vols / total_volume * 100), 2) if total_volume > 0 else 50.0

    # ── CVD trend ────────────────────────────────────────────────────────
    if buy_pressure_pct >= BUY_PRESSURE_BULLISH:
        cvd_trend = "bullish"
    elif buy_pressure_pct <= BUY_PRESSURE_BEARISH:
        cvd_trend = "bearish"
    else:
        cvd_trend = "neutral"

    # ── Momentum strength ────────────────────────────────────────────────
    cvd_ratio = abs(cvd_cumulative) / total_volume if total_volume > 0 else 0
    if cvd_ratio >= CVD_STRONG_RATIO:
        momentum_strength = "strong"
    elif cvd_ratio >= CVD_MODERATE_RATIO:
        momentum_strength = "moderate"
    else:
        momentum_strength = "weak"

    # ── Divergence detection ─────────────────────────────────────────────
    # Compare price direction vs CVD direction over the lookback window
    price_start = float(window["close"].iloc[0])
    price_end   = float(window["close"].iloc[-1])
    price_up    = price_end > price_start

    cvd_up = cvd_cumulative > 0

    if price_up and not cvd_up:
        divergence = "bearish_divergence"   # price up, sellers dominating → weak rally
    elif not price_up and cvd_up:
        divergence = "bullish_divergence"   # price down, buyers dominating → potential reversal
    else:
        divergence = "none"

    # ── Recent trend (last 5 candles vs previous 5) for recency ──────────
    if len(window) >= 10:
        recent_bp  = window["taker_buy"].tail(5).sum() / max(window["volume_f"].tail(5).sum(), 1) * 100
        earlier_bp = window["taker_buy"].head(5).sum() / max(window["volume_f"].head(5).sum(), 1) * 100
        pressure_accelerating = bool(recent_bp > earlier_bp + 3)
    else:
        recent_bp  = buy_pressure_pct
        pressure_accelerating = False

    return {
        "cvd_cumulative":         round(float(cvd_cumulative), 4),
        "cvd_trend":              cvd_trend,
        "buy_pressure_pct":       float(buy_pressure_pct),
        "recent_buy_pressure_pct": float(round(recent_bp, 2)),
        "pressure_accelerating":  bool(pressure_accelerating),
        "divergence":             divergence,
        "momentum_strength":      momentum_strength,
        "lookback_candles":       int(len(window)),
        "using_taker_data":       bool("taker_buy_base" in df.columns),
    }


def _no_data_result() -> dict:
    return {
        "cvd_cumulative":          None,
        "cvd_trend":               "no_data",
        "buy_pressure_pct":        None,
        "recent_buy_pressure_pct": None,
        "pressure_accelerating":   False,
        "divergence":              "no_data",
        "momentum_strength":       "no_data",
        "lookback_candles":        0,
        "using_taker_data":        False,
    }
