"""
Liquidity Sweep Detection — Smart Money Concepts (SMC)

Detects when price briefly breaks a liquidity level (Equal Highs / Equal Lows /
Swing Highs / Swing Lows) and then reverses back. This is the core SMC pattern
known as a "Liquidity Sweep" or "Stop Hunt":

  - SSL Sweep (Sell-Side Liquidity):
    Price wicks BELOW a swing low / equal low, then closes ABOVE it.
    Interpretation: Smart Money swept sell-side stop-losses → bullish reversal likely.

  - BSL Sweep (Buy-Side Liquidity):
    Price wicks ABOVE a swing high / equal high, then closes BELOW it.
    Interpretation: Smart Money swept buy-side stop-losses → bearish reversal likely.

  - Stop Hunt Candle:
    Single candle with a wick > 3× its body in one direction, closing the opposite way.

Sweep Quality Scoring (determines the confidence bonus):
──────────────────────────────────────────────────────
  Score 1 (sweep only)        → weak   → +4%  confidence bonus
  Score 2–3 (+ BOS or OB/FVG) → moderate → +8%  confidence bonus
  Score 4+ (+ BOS + OB + FVG) → strong  → +12% confidence bonus

Why dynamic scoring? Not all sweeps are equal. An isolated wick below a swing low
with no structure confirmation is much weaker evidence than a sweep followed by a
BOS, a bullish Order Block at the swept level, and an unfilled bullish FVG — the
latter is one of the strongest SMC entry setups.
"""

import pandas as pd
import numpy as np


# ── Quality thresholds ─────────────────────────────────────────────────────────
BONUS_WEAK     = 4   # Sweep happened, no structural confirmation
BONUS_MODERATE = 8   # Sweep + at least one confirmation (BOS or OB/FVG)
BONUS_STRONG   = 12  # Sweep + BOS/CHoCH + OB + FVG (full confluence stack)

ACTIVE_SWEEP_CANDLES = 8   # A sweep is "active" only if within this many candles
SWEEP_LOOKBACK       = 40  # How far back to look for swing levels to sweep


def detect_liquidity_sweeps(df: pd.DataFrame, smc: dict) -> dict:
    """
    Main entry point. Scan recent candles for SSL/BSL sweeps and stop-hunt candles.
    Returns the most recent active sweep and nearest liquidity levels.
    """
    if df is None or len(df) < 10:
        return _empty_result()

    highs   = df["high"].values
    lows    = df["low"].values
    closes  = df["close"].values
    opens   = df["open"].values
    n       = len(df)

    current_price = float(closes[-1])

    # Pre-compute swing highs and lows over the SWEEP_LOOKBACK window
    swing_highs, swing_lows = _find_swing_levels(highs, lows, lookback=SWEEP_LOOKBACK)

    recent_sweeps = []

    # Scan the last ACTIVE_SWEEP_CANDLES * 2 candles for sweep events
    scan_start = max(1, n - ACTIVE_SWEEP_CANDLES * 3)

    for i in range(scan_start, n - 1):
        candle_high  = float(highs[i])
        candle_low   = float(lows[i])
        candle_close = float(closes[i])
        candle_open  = float(opens[i])
        candles_ago  = (n - 1) - i

        candle_body  = abs(candle_close - candle_open)
        upper_wick   = candle_high - max(candle_close, candle_open)
        lower_wick   = min(candle_close, candle_open) - candle_low

        # ── SSL Sweep: wick below swing low, close above it ───────────────────
        # Find the nearest swing low formed BEFORE this candle
        prior_swing_lows = [p for p in swing_lows if p["index"] < i]
        if prior_swing_lows:
            nearest_swing_low = min(prior_swing_lows, key=lambda x: abs(x["index"] - i))
            sl_price = nearest_swing_low["price"]

            if candle_low < sl_price and candle_close > sl_price:
                score = _score_ssl_sweep(smc)
                sweep = _build_sweep(
                    sweep_type="ssl_sweep",
                    direction="bullish",
                    swept_level=sl_price,
                    extreme_reached=candle_low,
                    close_price=candle_close,
                    candles_ago=candles_ago,
                    score=score,
                )
                recent_sweeps.append(sweep)

        # ── BSL Sweep: wick above swing high, close below it ──────────────────
        prior_swing_highs = [p for p in swing_highs if p["index"] < i]
        if prior_swing_highs:
            nearest_swing_high = min(prior_swing_highs, key=lambda x: abs(x["index"] - i))
            sh_price = nearest_swing_high["price"]

            if candle_high > sh_price and candle_close < sh_price:
                score = _score_bsl_sweep(smc)
                sweep = _build_sweep(
                    sweep_type="bsl_sweep",
                    direction="bearish",
                    swept_level=sh_price,
                    extreme_reached=candle_high,
                    close_price=candle_close,
                    candles_ago=candles_ago,
                    score=score,
                )
                recent_sweeps.append(sweep)

        # ── Stop Hunt Candle: wick > 3× body, closes in opposite direction ────
        if candle_body > 0:
            if lower_wick > 3 * candle_body and candle_close > candle_open:
                score = 2  # Moderate by default — no swing-level confirmation
                recent_sweeps.append(_build_sweep(
                    sweep_type="stop_hunt_bullish",
                    direction="bullish",
                    swept_level=candle_low,
                    extreme_reached=candle_low,
                    close_price=candle_close,
                    candles_ago=candles_ago,
                    score=score,
                ))
            elif upper_wick > 3 * candle_body and candle_close < candle_open:
                score = 2
                recent_sweeps.append(_build_sweep(
                    sweep_type="stop_hunt_bearish",
                    direction="bearish",
                    swept_level=candle_high,
                    extreme_reached=candle_high,
                    close_price=candle_close,
                    candles_ago=candles_ago,
                    score=score,
                ))

    # Sort by recency (most recent first), deduplicate by direction
    recent_sweeps.sort(key=lambda s: s["candles_ago"])
    seen_dirs = set()
    deduped = []
    for s in recent_sweeps:
        if s["direction"] not in seen_dirs:
            deduped.append(s)
            seen_dirs.add(s["direction"])

    # Active sweep = most recent one within ACTIVE_SWEEP_CANDLES
    active_sweep = next(
        (s for s in deduped if s["candles_ago"] <= ACTIVE_SWEEP_CANDLES), None
    )

    # Nearest BSL / SSL levels from SMC liquidity zones
    zones = smc.get("liquidity_zones", {})
    buy_side  = [z["price"] for z in zones.get("buy_side", [])]
    sell_side = [z["price"] for z in zones.get("sell_side", [])]

    nearest_bsl = _nearest_level_above(buy_side, current_price)
    nearest_ssl = _nearest_level_below(sell_side, current_price)

    return {
        "recent_sweeps"    : deduped[:4],
        "active_sweep"     : active_sweep,
        "nearest_bsl_level": float(nearest_bsl) if nearest_bsl is not None else None,
        "nearest_ssl_level": float(nearest_ssl) if nearest_ssl is not None else None,
    }


# ── Internal helpers ──────────────────────────────────────────────────────────

def _find_swing_levels(highs, lows, lookback: int = 40) -> tuple:
    """Return swing high and low pivot points over the last `lookback` bars."""
    n = len(highs)
    start = max(2, n - lookback)

    swing_highs = []
    swing_lows  = []

    for i in range(start, n - 2):
        if highs[i] > highs[i - 1] and highs[i] > highs[i - 2] \
                and highs[i] > highs[i + 1] and highs[i] > highs[i + 2]:
            swing_highs.append({"index": i, "price": float(highs[i])})
        if lows[i] < lows[i - 1] and lows[i] < lows[i - 2] \
                and lows[i] < lows[i + 1] and lows[i] < lows[i + 2]:
            swing_lows.append({"index": i, "price": float(lows[i])})

    return swing_highs, swing_lows


def _score_ssl_sweep(smc: dict) -> int:
    """
    Score an SSL Sweep (bullish) based on SMC confluence:
      +1 base (sweep happened)
      +1 bullish BOS or CHoCH present
      +1 bullish Order Block near swept level
      +1 bullish FVG near swept level
    """
    score = 1

    structure = smc.get("market_structure", {})
    if any(b["direction"] == "bullish" for b in structure.get("bos", [])):
        score += 1
    elif any(c["direction"] == "bullish" for c in structure.get("choch", [])):
        score += 1

    if any(ob["type"] == "bullish" for ob in smc.get("order_blocks", [])):
        score += 1

    if any(fvg["type"] == "bullish" for fvg in smc.get("fair_value_gaps", [])):
        score += 1

    return score


def _score_bsl_sweep(smc: dict) -> int:
    """Score a BSL Sweep (bearish) — mirror of _score_ssl_sweep."""
    score = 1

    structure = smc.get("market_structure", {})
    if any(b["direction"] == "bearish" for b in structure.get("bos", [])):
        score += 1
    elif any(c["direction"] == "bearish" for c in structure.get("choch", [])):
        score += 1

    if any(ob["type"] == "bearish" for ob in smc.get("order_blocks", [])):
        score += 1

    if any(fvg["type"] == "bearish" for fvg in smc.get("fair_value_gaps", [])):
        score += 1

    return score


def _score_to_strength(score: int) -> tuple:
    """Map quality score to (label, bonus)."""
    if score >= 4:
        return "strong", BONUS_STRONG
    elif score >= 2:
        return "moderate", BONUS_MODERATE
    else:
        return "weak", BONUS_WEAK


def _build_sweep(*, sweep_type, direction, swept_level, extreme_reached,
                 close_price, candles_ago, score) -> dict:
    strength_label, bonus = _score_to_strength(score)

    readable = {
        "ssl_sweep"          : "SSL Sweep — Smart Money swept sell-side liquidity → bullish reversal expected",
        "bsl_sweep"          : "BSL Sweep — Smart Money swept buy-side liquidity → bearish reversal expected",
        "stop_hunt_bullish"  : "Stop Hunt (Bullish) — long lower wick swept sell-side stops, closed bullish",
        "stop_hunt_bearish"  : "Stop Hunt (Bearish) — long upper wick swept buy-side stops, closed bearish",
    }

    return {
        "type"             : sweep_type,
        "direction"        : direction,
        "swept_level"      : round(float(swept_level), 8),
        "extreme_reached"  : round(float(extreme_reached), 8),
        "close_price"      : round(float(close_price), 8),
        "candles_ago"      : candles_ago,
        "quality_score"    : score,
        "strength"         : strength_label,
        "confidence_boost" : bonus,
        "description"      : readable.get(sweep_type, sweep_type),
    }


def _nearest_level_above(levels: list, current_price: float):
    above = [p for p in levels if p > current_price]
    return min(above) if above else (max(levels) if levels else None)


def _nearest_level_below(levels: list, current_price: float):
    below = [p for p in levels if p < current_price]
    return max(below) if below else (min(levels) if levels else None)


def _empty_result() -> dict:
    return {
        "recent_sweeps"    : [],
        "active_sweep"     : None,
        "nearest_bsl_level": None,
        "nearest_ssl_level": None,
    }
