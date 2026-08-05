import pandas as pd
import pandas_ta as ta
import numpy as np
from typing import Optional


def find_support_resistance(df: pd.DataFrame, window: int = 10) -> dict:
    """Find key support and resistance levels using pivot points.
    
    IMPORTANT: Supports MUST be below current price, resistances MUST be above.
    """
    highs = df["high"].values
    lows = df["low"].values
    closes = df["close"].values
    current_price = float(closes[-1])

    resistance_levels = []
    support_levels = []

    for i in range(window, len(df) - window):
        # Resistance: local maximum
        if highs[i] == max(highs[i - window:i + window + 1]):
            price = float(highs[i])
            # Resistance MUST be ABOVE current price
            if price > current_price:
                resistance_levels.append({
                    "price": price,
                    "index": int(i),
                    "timestamp": str(df["open_time"].iloc[i]),
                    "type": "resistance",
                    "strength": _calculate_level_strength(highs, lows, price)
                })
        # Support: local minimum
        if lows[i] == min(lows[i - window:i + window + 1]):
            price = float(lows[i])
            # Support MUST be BELOW current price
            if price < current_price:
                support_levels.append({
                    "price": price,
                    "index": int(i),
                    "timestamp": str(df["open_time"].iloc[i]),
                    "type": "support",
                    "strength": _calculate_level_strength(highs, lows, price)
                })

    # Merge close levels (within 0.5% of each other)
    resistance_levels = _merge_close_levels(resistance_levels, 0.005)
    support_levels = _merge_close_levels(support_levels, 0.005)

    # Sort: resistances closest to price first (most relevant), supports closest to price first
    resistance_levels = sorted(resistance_levels, key=lambda x: x["price"])[:5]  # Lowest resistances first (closest)
    support_levels = sorted(support_levels, key=lambda x: x["price"], reverse=True)[:5]  # Highest supports first (closest)

    # Also find the strongest of each for the report
    strong_support = max(support_levels, key=lambda x: x["strength"])["price"] if support_levels else None
    strong_resistance = min(resistance_levels, key=lambda x: x["price"])["price"] if resistance_levels else None

    return {
        "support": support_levels,
        "resistance": resistance_levels,
        "strong_support": strong_support,
        "strong_resistance": strong_resistance,
        "current_price": current_price
    }


def _calculate_level_strength(highs, lows, price: float, tolerance: float = 0.005) -> int:
    """Count how many times price has touched this level."""
    touches = 0
    for i in range(len(highs)):
        if abs(highs[i] - price) / price <= tolerance or abs(lows[i] - price) / price <= tolerance:
            touches += 1
    return touches


def _merge_close_levels(levels: list, tolerance: float) -> list:
    """Merge levels that are within tolerance % of each other."""
    if not levels:
        return []
    merged = []
    levels = sorted(levels, key=lambda x: x["price"])
    current = levels[0]
    for lvl in levels[1:]:
        if abs(lvl["price"] - current["price"]) / current["price"] <= tolerance:
            # Keep the stronger one
            if lvl["strength"] > current["strength"]:
                current = lvl
        else:
            merged.append(current)
            current = lvl
    merged.append(current)
    return merged


def calculate_moving_averages(df: pd.DataFrame) -> dict:
    """Calculate key moving averages."""
    close = df["close"]
    result = {}

    for period in [20, 50, 100, 200]:
        ema = ta.ema(close, length=period)
        sma = ta.sma(close, length=period)
        if ema is not None and not ema.empty:
            result[f"ema_{period}"] = float(ema.iloc[-1]) if not pd.isna(ema.iloc[-1]) else None
            result[f"_ema_{period}_series"] = ema
        if sma is not None and not sma.empty:
            result[f"sma_{period}"] = float(sma.iloc[-1]) if not pd.isna(sma.iloc[-1]) else None

    # Trend direction — based on price position vs each EMA independently
    # This avoids false neutrals when EMAs aren't perfectly stacked (e.g. choppy markets)
    last_close = float(close.iloc[-1])
    ema20 = result.get("ema_20")
    ema50 = result.get("ema_50")
    ema200 = result.get("ema_200")

    # Count how many EMAs price is above/below
    ema_checks = [v for v in [ema20, ema50, ema200] if v is not None]
    above = sum(1 for e in ema_checks if last_close > e)
    below = sum(1 for e in ema_checks if last_close < e)
    total_emas = len(ema_checks)

    if total_emas >= 3:
        if below == 3:
            result["trend"] = "strong_downtrend"  # Below ALL 3 EMAs
        elif above == 3:
            result["trend"] = "strong_uptrend"    # Above ALL 3 EMAs
        elif below == 2:
            result["trend"] = "downtrend"
        elif above == 2:
            result["trend"] = "uptrend"
        else:
            result["trend"] = "neutral"  # Mixed - 1 above, 2 above, or equal
    elif total_emas >= 2:
        if below >= 2:
            result["trend"] = "downtrend"
        elif above >= 2:
            result["trend"] = "uptrend"
        else:
            result["trend"] = "neutral"
    elif total_emas == 1:
        e = ema_checks[0]
        result["trend"] = "uptrend" if last_close > e else "downtrend"
    else:
        result["trend"] = "neutral"

    # Cross Signals (Death/Golden Cross)
    cross_signal = None
    ema50_s = result.get("_ema_50_series")
    ema200_s = result.get("_ema_200_series")
    if ema50_s is not None and ema200_s is not None and len(ema50_s) >= 2 and len(ema200_s) >= 2:
        curr_50 = float(ema50_s.iloc[-1])
        prev_50 = float(ema50_s.iloc[-2])
        curr_200 = float(ema200_s.iloc[-1])
        prev_200 = float(ema200_s.iloc[-2])
        if prev_50 <= prev_200 and curr_50 > curr_200:
            cross_signal = "Golden Cross"
        elif prev_50 >= prev_200 and curr_50 < curr_200:
            cross_signal = "Death Cross"
    result["cross_signal"] = cross_signal

    # Cleanup temporary series
    for k in list(result.keys()):
        if k.startswith("_ema_") and k.endswith("_series"):
            del result[k]

    # Also store raw EMA distance info for the prompt
    result["price_vs_emas"] = {
        "above_count": above,
        "below_count": below,
        "ema20_pct": round((last_close - ema20) / ema20 * 100, 3) if ema20 else None,
        "ema50_pct": round((last_close - ema50) / ema50 * 100, 3) if ema50 else None,
        "ema200_pct": round((last_close - ema200) / ema200 * 100, 3) if ema200 else None,
    }

    # Overlay lines for chart
    result["overlays"] = []
    colors = {"ema_20": "#00D4FF", "ema_50": "#FFC107", "ema_200": "#FF1744"}
    for key, color in colors.items():
        if result.get(key):
            result["overlays"].append({
                "type": "line",
                "label": key.upper(),
                "color": color,
                "value": result[key]
            })

    return result


def calculate_rsi(df: pd.DataFrame, period: int = 14) -> dict:
    """Calculate RSI and detect divergence."""
    rsi = ta.rsi(df["close"], length=period)
    if rsi is None or rsi.empty:
        return {}

    last_rsi = float(rsi.iloc[-1]) if not pd.isna(rsi.iloc[-1]) else None

    condition = "neutral"
    if last_rsi:
        if last_rsi >= 70:
            condition = "overbought"
        elif last_rsi > 55:
            condition = "bullish"
        elif last_rsi >= 45:
            condition = "neutral"
        elif last_rsi > 30:
            condition = "bearish"
        else:
            condition = "oversold"

    # RSI series for chart (last 50 values)
    rsi_series = []
    for i, (idx, val) in enumerate(rsi.tail(50).items()):
        if not pd.isna(val):
            rsi_series.append({
                "timestamp": str(df["open_time"].iloc[-(50 - i)]),
                "value": float(val)
            })

    return {
        "current": last_rsi,
        "condition": condition,
        "series": rsi_series
    }


def calculate_macd(df: pd.DataFrame) -> dict:
    """Calculate MACD indicator."""
    macd_df = ta.macd(df["close"], fast=12, slow=26, signal=9)
    if macd_df is None or macd_df.empty:
        return {}

    last = macd_df.iloc[-1]
    macd_col = [c for c in macd_df.columns if "MACD_" in c and "MACDs" not in c and "MACDh" not in c]
    signal_col = [c for c in macd_df.columns if "MACDs_" in c]
    hist_col = [c for c in macd_df.columns if "MACDh_" in c]

    result = {}
    if macd_col:
        result["macd"] = float(last[macd_col[0]]) if not pd.isna(last[macd_col[0]]) else None
    if signal_col:
        result["signal"] = float(last[signal_col[0]]) if not pd.isna(last[signal_col[0]]) else None
    if hist_col:
        hist_val = float(last[hist_col[0]]) if not pd.isna(last[hist_col[0]]) else None
        result["histogram"] = hist_val
        result["trend"] = "bullish" if (hist_val and hist_val > 0) else "bearish"

    if result.get("macd") and result.get("signal"):
        result["crossover"] = "bullish" if result["macd"] > result["signal"] else "bearish"

    return result


def calculate_bollinger_bands(df: pd.DataFrame, period: int = 20, std: float = 2.0) -> dict:
    """Calculate Bollinger Bands."""
    bb = ta.bbands(df["close"], length=period, std=std)
    if bb is None or bb.empty:
        return {}

    last = bb.iloc[-1]
    lower_col = [c for c in bb.columns if "BBL" in c]
    mid_col = [c for c in bb.columns if "BBM" in c]
    upper_col = [c for c in bb.columns if "BBU" in c]
    bw_col = [c for c in bb.columns if "BBB" in c]

    result = {}
    if lower_col:
        result["lower"] = float(last[lower_col[0]]) if not pd.isna(last[lower_col[0]]) else None
    if mid_col:
        result["middle"] = float(last[mid_col[0]]) if not pd.isna(last[mid_col[0]]) else None
    if upper_col:
        result["upper"] = float(last[upper_col[0]]) if not pd.isna(last[upper_col[0]]) else None
    if bw_col:
        result["bandwidth"] = float(last[bw_col[0]]) if not pd.isna(last[bw_col[0]]) else None

    last_close = float(df["close"].iloc[-1])
    if result.get("lower") and result.get("upper"):
        if last_close <= result["lower"]:
            result["position"] = "below_lower"
        elif last_close >= result["upper"]:
            result["position"] = "above_upper"
        else:
            result["position"] = "inside"

    return result


def detect_candlestick_patterns(df: pd.DataFrame) -> list:
    """Detect key candlestick patterns."""
    patterns = []
    close = df["close"].values
    open_ = df["open"].values
    high = df["high"].values
    low = df["low"].values

    for i in range(2, min(len(df), 5)):
        idx = len(df) - i
        if idx < 1:
            continue

        o, h, l, c = open_[idx], high[idx], low[idx], close[idx]
        body = abs(c - o)
        upper_wick = h - max(o, c)
        lower_wick = min(o, c) - l
        total_range = h - l

        if total_range == 0:
            continue

        ts = str(df["open_time"].iloc[idx])

        # Doji
        if body / total_range < 0.1:
            patterns.append({"name": "Doji", "timestamp": ts, "direction": "neutral", "strength": 2})

        # Hammer (bullish)
        if lower_wick > 2 * body and upper_wick < body:
            patterns.append({"name": "Hammer", "timestamp": ts, "direction": "bullish", "strength": 3})

        # Shooting Star (bearish)
        if upper_wick > 2 * body and lower_wick < body:
            patterns.append({"name": "Shooting Star", "timestamp": ts, "direction": "bearish", "strength": 3})

        # Bullish Engulfing
        if idx > 0:
            prev_o, prev_c = open_[idx - 1], close[idx - 1]
            if prev_c < prev_o and c > o and c > prev_o and o < prev_c:
                patterns.append({"name": "Bullish Engulfing", "timestamp": ts, "direction": "bullish", "strength": 4})
            if prev_c > prev_o and c < o and c < prev_o and o > prev_c:
                patterns.append({"name": "Bearish Engulfing", "timestamp": ts, "direction": "bearish", "strength": 4})

        # Marubozu
        if body / total_range > 0.95 and c > o:
            patterns.append({"name": "Bullish Marubozu", "timestamp": ts, "direction": "bullish", "strength": 3})
        if body / total_range > 0.95 and c < o:
            patterns.append({"name": "Bearish Marubozu", "timestamp": ts, "direction": "bearish", "strength": 3})

    return patterns[:5]  # Return most recent 5 patterns


def detect_chart_patterns(df: pd.DataFrame) -> list:
    """Detect higher-level chart patterns: Double/Triple Top/Bottom."""
    patterns = []
    highs = df["high"].values
    lows = df["low"].values

    # --- Tops (bearish): Triple Top takes priority over Double Top when both match ---
    recent_highs = []
    for i in range(5, len(df) - 2):
        if highs[i] > highs[i - 1] and highs[i] > highs[i + 1] and highs[i] > highs[i - 2] and highs[i] > highs[i + 2]:
            recent_highs.append((i, highs[i]))

    triple_top_found = False
    if len(recent_highs) >= 3:
        i1, v1 = recent_highs[-3]
        i2, v2 = recent_highs[-2]
        i3, v3 = recent_highs[-1]
        if abs(v1 - v2) / v1 < 0.02 and abs(v2 - v3) / v2 < 0.02 and i2 - i1 > 5 and i3 - i2 > 5:
            patterns.append({
                "name": "Triple Top",
                "direction": "bearish",
                "strength": 5,
                "levels": {"peak1": float(v1), "peak2": float(v2), "peak3": float(v3)},
            })
            triple_top_found = True

    if not triple_top_found and len(recent_highs) >= 2:
        h1_idx, h1_val = recent_highs[-2]
        h2_idx, h2_val = recent_highs[-1]
        if abs(h1_val - h2_val) / h1_val < 0.02 and h2_idx - h1_idx > 5:
            patterns.append({
                "name": "Double Top",
                "direction": "bearish",
                "strength": 4,
                "levels": {"peak1": float(h1_val), "peak2": float(h2_val)},
            })

    # --- Bottoms (bullish): Triple Bottom takes priority over Double Bottom ---
    recent_lows = []
    for i in range(5, len(df) - 2):
        if lows[i] < lows[i - 1] and lows[i] < lows[i + 1] and lows[i] < lows[i - 2] and lows[i] < lows[i + 2]:
            recent_lows.append((i, lows[i]))

    triple_bottom_found = False
    if len(recent_lows) >= 3:
        i1, v1 = recent_lows[-3]
        i2, v2 = recent_lows[-2]
        i3, v3 = recent_lows[-1]
        if abs(v1 - v2) / v1 < 0.02 and abs(v2 - v3) / v2 < 0.02 and i2 - i1 > 5 and i3 - i2 > 5:
            patterns.append({
                "name": "Triple Bottom",
                "direction": "bullish",
                "strength": 5,
                "levels": {"trough1": float(v1), "trough2": float(v2), "trough3": float(v3)},
            })
            triple_bottom_found = True

    if not triple_bottom_found and len(recent_lows) >= 2:
        l1_idx, l1_val = recent_lows[-2]
        l2_idx, l2_val = recent_lows[-1]
        if abs(l1_val - l2_val) / l1_val < 0.02 and l2_idx - l1_idx > 5:
            patterns.append({
                "name": "Double Bottom",
                "direction": "bullish",
                "strength": 4,
                "levels": {"trough1": float(l1_val), "trough2": float(l2_val)},
            })

    return patterns


def detect_head_and_shoulders(df: pd.DataFrame) -> list:
    """
    Detect Head & Shoulders (bearish reversal) and Inverse Head & Shoulders (bullish
    reversal): three peaks/troughs where the middle one (head) is the most extreme
    and the outer two (shoulders) are roughly symmetric, connected by a neckline.
    """
    highs = df["high"].values
    lows = df["low"].values
    patterns = []

    # --- Regular H&S: three peaks, middle one (head) is the highest ---
    peaks = []
    for i in range(2, len(df) - 2):
        if highs[i] > highs[i - 1] and highs[i] > highs[i - 2] and highs[i] > highs[i + 1] and highs[i] > highs[i + 2]:
            peaks.append((i, float(highs[i])))

    if len(peaks) >= 3:
        (l_idx, l_val), (h_idx, h_val), (r_idx, r_val) = peaks[-3], peaks[-2], peaks[-1]
        symmetry_dev = abs(l_val - r_val) / l_val if l_val else 1.0
        if h_val > l_val and h_val > r_val and symmetry_dev < 0.05 and l_idx < h_idx < r_idx:
            trough1 = float(np.min(lows[l_idx:h_idx + 1]))
            trough2 = float(np.min(lows[h_idx:r_idx + 1]))
            neckline = (trough1 + trough2) / 2
            patterns.append({
                "name": "Head and Shoulders",
                "direction": "bearish",
                "confidence": int(max(55, min(90, round(90 - symmetry_dev * 500)))),
                "levels": {
                    "left_shoulder": l_val,
                    "head": h_val,
                    "right_shoulder": r_val,
                    "neckline": round(neckline, 8),
                    "target": round(neckline - (h_val - neckline), 8),
                }
            })

    # --- Inverse H&S: three troughs, middle one (head) is the lowest ---
    troughs = []
    for i in range(2, len(df) - 2):
        if lows[i] < lows[i - 1] and lows[i] < lows[i - 2] and lows[i] < lows[i + 1] and lows[i] < lows[i + 2]:
            troughs.append((i, float(lows[i])))

    if len(troughs) >= 3:
        (l_idx, l_val), (h_idx, h_val), (r_idx, r_val) = troughs[-3], troughs[-2], troughs[-1]
        symmetry_dev = abs(l_val - r_val) / l_val if l_val else 1.0
        if h_val < l_val and h_val < r_val and symmetry_dev < 0.05 and l_idx < h_idx < r_idx:
            peak1 = float(np.max(highs[l_idx:h_idx + 1]))
            peak2 = float(np.max(highs[h_idx:r_idx + 1]))
            neckline = (peak1 + peak2) / 2
            patterns.append({
                "name": "Inverse Head and Shoulders",
                "direction": "bullish",
                "confidence": int(max(55, min(90, round(90 - symmetry_dev * 500)))),
                "levels": {
                    "left_shoulder": l_val,
                    "head": h_val,
                    "right_shoulder": r_val,
                    "neckline": round(neckline, 8),
                    "target": round(neckline + (neckline - h_val), 8),
                }
            })

    return patterns


def _find_pivot_points(df: pd.DataFrame, window: int = 3) -> tuple:
    """Find local swing highs/lows as (index, price) tuples, for trendline fitting."""
    highs = df["high"].values
    lows = df["low"].values
    swing_highs = []
    swing_lows = []
    for i in range(window, len(df) - window):
        if highs[i] == max(highs[i - window:i + window + 1]):
            swing_highs.append((i, float(highs[i])))
        if lows[i] == min(lows[i - window:i + window + 1]):
            swing_lows.append((i, float(lows[i])))
    return swing_highs, swing_lows


def _fit_trendline(points: list) -> Optional[dict]:
    """Fit a straight line (least squares) through (index, price) points."""
    if len(points) < 2:
        return None
    xs = np.array([p[0] for p in points], dtype=float)
    ys = np.array([p[1] for p in points], dtype=float)
    slope, intercept = np.polyfit(xs, ys, 1)
    return {"slope": float(slope), "intercept": float(intercept)}


def _line_value(line: dict, x: float) -> float:
    return line["slope"] * x + line["intercept"]


def _line_fit_r2(points: list, line: dict) -> float:
    """R^2 goodness of fit — how well the points actually sit on the fitted line."""
    xs = np.array([p[0] for p in points], dtype=float)
    ys = np.array([p[1] for p in points], dtype=float)
    preds = line["slope"] * xs + line["intercept"]
    ss_res = float(np.sum((ys - preds) ** 2))
    ss_tot = float(np.sum((ys - np.mean(ys)) ** 2))
    return 1.0 if ss_tot == 0 else max(0.0, 1 - ss_res / ss_tot)


def detect_triangles_and_wedges(df: pd.DataFrame, lookback: int = 80) -> list:
    """
    Detect Triangles (Ascending/Descending/Symmetrical) and Wedges (Rising/Falling)
    by fitting trendlines through recent swing highs (resistance) and swing lows
    (support), then classifying by slope direction and whether the lines converge.
    """
    recent_df = df.tail(lookback).reset_index(drop=True)
    swing_highs, swing_lows = _find_pivot_points(recent_df, window=3)

    if len(swing_highs) < 3 or len(swing_lows) < 3:
        return []

    upper = _fit_trendline(swing_highs[-5:])
    lower = _fit_trendline(swing_lows[-5:])
    if not upper or not lower:
        return []

    avg_price = float(recent_df["close"].mean())
    if avg_price <= 0:
        return []

    # Normalize slopes to %-per-candle so the flat/sloped thresholds aren't price-scale dependent
    upper_slope_pct = (upper["slope"] / avg_price) * 100
    lower_slope_pct = (lower["slope"] / avg_price) * 100
    flat = 0.02  # % per candle considered "flat"

    end_x = len(recent_df) - 1
    gap_start = _line_value(upper, 0) - _line_value(lower, 0)
    gap_end = _line_value(upper, end_x) - _line_value(lower, end_x)

    # Must be converging (narrowing gap) with the resistance line still above support
    if gap_end <= 0 or gap_end >= gap_start:
        return []

    upper_r2 = _line_fit_r2(swing_highs[-5:], upper)
    lower_r2 = _line_fit_r2(swing_lows[-5:], lower)
    confidence = int(max(55, min(90, round(55 + ((upper_r2 + lower_r2) / 2) * 35))))

    trend_bias = "bullish" if float(recent_df["close"].iloc[-1]) > float(recent_df["close"].iloc[0]) else "bearish"

    if abs(upper_slope_pct) <= flat and lower_slope_pct > flat:
        name, direction = "Ascending Triangle", "bullish"
    elif upper_slope_pct < -flat and abs(lower_slope_pct) <= flat:
        name, direction = "Descending Triangle", "bearish"
    elif upper_slope_pct < -flat and lower_slope_pct > flat:
        name, direction = "Symmetrical Triangle", trend_bias
    elif upper_slope_pct < -flat and lower_slope_pct < -flat:
        name, direction = "Falling Wedge", "bullish"
    elif upper_slope_pct > flat and lower_slope_pct > flat:
        name, direction = "Rising Wedge", "bearish"
    else:
        return []

    return [{
        "name": name,
        "direction": direction,
        "confidence": confidence,
        "levels": {
            "resistance_now": round(float(_line_value(upper, end_x)), 8),
            "support_now": round(float(_line_value(lower, end_x)), 8),
        },
        "resistance_points": [
            {"timestamp": int(recent_df["open_time"].iloc[0].timestamp()), "price": round(float(_line_value(upper, 0)), 8)},
            {"timestamp": int(recent_df["open_time"].iloc[end_x].timestamp()), "price": round(float(_line_value(upper, end_x)), 8)},
        ],
        "support_points": [
            {"timestamp": int(recent_df["open_time"].iloc[0].timestamp()), "price": round(float(_line_value(lower, 0)), 8)},
            {"timestamp": int(recent_df["open_time"].iloc[end_x].timestamp()), "price": round(float(_line_value(lower, end_x)), 8)},
        ]
    }]


def calculate_atr(df: pd.DataFrame, period: int = 14) -> dict:
    """Calculate Average True Range (ATR) for volatility.
    
    Falls back to manual calculation if pandas_ta returns NaN.
    """
    # Try pandas_ta first
    atr_series = ta.atr(df["high"], df["low"], df["close"], length=period)
    
    last_atr = None
    if atr_series is not None and not atr_series.empty:
        val = atr_series.iloc[-1]
        if not pd.isna(val):
            last_atr = float(val)
    
    # Manual fallback: calculate TR manually if pandas_ta returned NaN
    if last_atr is None and len(df) >= period + 1:
        highs = df["high"].values
        lows = df["low"].values
        closes = df["close"].values
        trs = []
        for i in range(1, len(df)):
            tr = max(
                highs[i] - lows[i],
                abs(highs[i] - closes[i - 1]),
                abs(lows[i] - closes[i - 1])
            )
            trs.append(tr)
        if len(trs) >= period:
            last_atr = float(np.mean(trs[-period:]))
    
    return {"current": last_atr}


def run_classical_analysis(df: pd.DataFrame) -> dict:
    """Run all classical technical analysis."""
    sr = find_support_resistance(df)
    mas = calculate_moving_averages(df)
    rsi = calculate_rsi(df)
    macd = calculate_macd(df)
    bb = calculate_bollinger_bands(df)
    atr = calculate_atr(df)
    candle_patterns = detect_candlestick_patterns(df)
    chart_patterns = (
        detect_chart_patterns(df)
        + detect_head_and_shoulders(df)
        + detect_triangles_and_wedges(df)
    )

    # ── Continuous Bias Scoring (0 to 100) ───────────────────────────────────
    # Weights for each indicator
    w_ma = 0.35
    w_rsi = 0.25
    w_macd = 0.20
    w_patterns = 0.20

    # Scores range from -1.0 (strongly bearish) to +1.0 (strongly bullish)
    score_ma = 0.0
    score_rsi = 0.0
    score_macd = 0.0
    score_patterns = 0.0

    # 1. MA Score
    trend = mas.get("trend", "neutral")
    if trend == "strong_uptrend": score_ma = 1.0
    elif trend == "strong_downtrend": score_ma = -1.0
    elif trend == "uptrend": score_ma = 0.5
    elif trend == "downtrend": score_ma = -0.5
    
    # 2. RSI Score
    curr_rsi = rsi.get("current")
    if curr_rsi is not None:
        if curr_rsi >= 70: score_rsi = 1.0
        elif curr_rsi <= 30: score_rsi = -1.0
        elif curr_rsi > 50: score_rsi = (curr_rsi - 50) / 20.0
        elif curr_rsi < 50: score_rsi = (curr_rsi - 50) / 20.0
        
    # 3. MACD Score
    hist = macd.get("histogram")
    crossover = macd.get("crossover", "neutral")
    if crossover == "bullish": score_macd += 0.5
    elif crossover == "bearish": score_macd -= 0.5
    if hist is not None:
        if hist > 0: score_macd += 0.5
        elif hist < 0: score_macd -= 0.5

    # 4. Patterns Score
    bullish_p = 0
    bearish_p = 0
    for p in candle_patterns:
        if p["direction"] == "bullish": bullish_p += 1
        elif p["direction"] == "bearish": bearish_p += 1
    for p in chart_patterns:
        if p["direction"] == "bullish": bullish_p += 2
        elif p["direction"] == "bearish": bearish_p += 2
        
    net_p = bullish_p - bearish_p
    if net_p > 0: score_patterns = min(net_p / 3.0, 1.0)
    elif net_p < 0: score_patterns = max(net_p / 3.0, -1.0)

    # ── Final Calculation ──
    total_score = (score_ma * w_ma) + (score_rsi * w_rsi) + (score_macd * w_macd) + (score_patterns * w_patterns)
    
    if total_score > 0.1:
        bias = "bullish"
        raw_conf = 50 + (abs(total_score) * 32)
        confidence = int(min(82, round(raw_conf)))
    elif total_score < -0.1:
        bias = "bearish"
        raw_conf = 50 + (abs(total_score) * 32)
        confidence = int(min(82, round(raw_conf)))
    else:
        bias = "neutral"
        confidence = 50

    bullish_signals = int(bullish_p + (1 if score_ma > 0 else 0) + (1 if score_rsi > 0 else 0) + (1 if score_macd > 0 else 0))
    bearish_signals = int(bearish_p + (1 if score_ma < 0 else 0) + (1 if score_rsi < 0 else 0) + (1 if score_macd < 0 else 0))

    return {
        "support_resistance": sr,
        "moving_averages": mas,
        "rsi": rsi,
        "macd": macd,
        "atr": atr,
        "bollinger_bands": bb,
        "chart_patterns": chart_patterns,
        "candle_patterns": candle_patterns,
        "bias": bias,
        "confidence": confidence,
        "bullish_signals": bullish_signals,
        "bearish_signals": bearish_signals
    }
