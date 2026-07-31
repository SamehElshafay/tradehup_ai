import pandas as pd
import numpy as np
from typing import Optional, Tuple


def get_fibonacci_levels(high: float, low: float, trend: str = "uptrend") -> dict:
    """
    Calculate Fibonacci retracement and extension levels.
    For uptrend: measure from swing low to swing high
    For downtrend: measure from swing high to swing low
    """
    diff = high - low
    ratios = {
        "0": 0.0,
        "0.236": 0.236,
        "0.382": 0.382,
        "0.5": 0.5,
        "0.618": 0.618,
        "0.786": 0.786,
        "1.0": 1.0,
        "1.272": 1.272,
        "1.618": 1.618,
    }
    levels = {}
    if trend == "uptrend":
        for label, ratio in ratios.items():
            levels[label] = float(high - (diff * ratio))
    else:
        for label, ratio in ratios.items():
            levels[label] = float(low + (diff * ratio))

    return {
        "trend": trend,
        "swing_high": float(high),
        "swing_low": float(low),
        "levels": levels
    }


def _find_swing_points(df: pd.DataFrame, window: int = 5) -> Tuple[list, list]:
    """Find swing highs and lows for harmonic pattern detection."""
    highs = df["high"].values
    lows = df["low"].values
    times = df["open_time"].tolist()

    swing_highs = []
    swing_lows = []

    for i in range(window, len(df) - window):
        if highs[i] == max(highs[i - window:i + window + 1]):
            swing_highs.append({"index": i, "price": float(highs[i]), "timestamp": str(times[i])})
        if lows[i] == min(lows[i - window:i + window + 1]):
            swing_lows.append({"index": i, "price": float(lows[i]), "timestamp": str(times[i])})

    return swing_highs, swing_lows


def _check_ratio(actual: float, expected: float, tolerance: float = 0.1) -> bool:
    """Check if actual ratio matches expected within tolerance."""
    return abs(actual - expected) <= tolerance * expected


def _pattern_confidence(deviations: list) -> int:
    """
    Score pattern confidence (55-95) based on how closely the actual Fibonacci
    ratios match the ideal ratios for the pattern, instead of a fixed constant —
    a near-perfect ratio match scores higher than a pattern that barely qualified.
    """
    if not deviations:
        return 70
    avg_dev = sum(deviations) / len(deviations)
    score = 95 - (avg_dev * 150)
    return int(max(55, min(95, round(score))))


def detect_gartley(df: pd.DataFrame) -> Optional[dict]:
    """
    Detect Gartley XABCD harmonic pattern.
    Ratios: B=0.618 of XA, C=0.382-0.886 of AB, D=0.786 of XA
    """
    swing_highs, swing_lows = _find_swing_points(df)

    if len(swing_highs) < 3 or len(swing_lows) < 3:
        return None

    # Look for bullish Gartley (XABCD where X and B are lows, A, C, D are highs or vice versa)
    # Simplified: check last 5 swing points
    all_swings = sorted(
        [{"type": "high", **h} for h in swing_highs[-3:]] +
        [{"type": "low", **l} for l in swing_lows[-3:]],
        key=lambda x: x["index"]
    )

    if len(all_swings) < 5:
        return None

    X, A, B, C, D = all_swings[-5], all_swings[-4], all_swings[-3], all_swings[-2], all_swings[-1]

    XA = abs(A["price"] - X["price"])
    AB = abs(B["price"] - A["price"])
    BC = abs(C["price"] - B["price"])
    CD = abs(D["price"] - C["price"])

    if XA == 0 or AB == 0:
        return None

    AB_XA = AB / XA
    BC_AB = BC / AB if AB > 0 else 0
    CD_BC = CD / BC if BC > 0 else 0
    AD_XA = abs(D["price"] - A["price"]) / XA

    # Gartley ratios
    if (
        _check_ratio(AB_XA, 0.618, 0.1) and
        0.382 <= BC_AB <= 0.886 and
        _check_ratio(AD_XA, 0.786, 0.15)
    ):
        direction = "bullish" if D["price"] < X["price"] else "bearish"
        return {
            "pattern": "Gartley",
            "direction": direction,
            "points": {"X": X, "A": A, "B": B, "C": C, "D": D},
            "completion": float(D["price"]),
            "target1": float(D["price"] + (0.382 * CD)) if direction == "bullish" else float(D["price"] - (0.382 * CD)),
            "target2": float(D["price"] + (0.618 * CD)) if direction == "bullish" else float(D["price"] - (0.618 * CD)),
            "stop_loss": float(X["price"]),
            "confidence": _pattern_confidence([
                abs(AB_XA - 0.618) / 0.618,
                abs(AD_XA - 0.786) / 0.786,
            ])
        }

    return None


def detect_bat(df: pd.DataFrame) -> Optional[dict]:
    """
    Detect Bat harmonic pattern.
    Ratios: B=0.382-0.5 of XA, C=0.382-0.886 of AB, D=0.886 of XA
    """
    swing_highs, swing_lows = _find_swing_points(df)

    if len(swing_highs) < 3 or len(swing_lows) < 3:
        return None

    all_swings = sorted(
        [{"type": "high", **h} for h in swing_highs[-3:]] +
        [{"type": "low", **l} for l in swing_lows[-3:]],
        key=lambda x: x["index"]
    )

    if len(all_swings) < 5:
        return None

    X, A, B, C, D = all_swings[-5], all_swings[-4], all_swings[-3], all_swings[-2], all_swings[-1]

    XA = abs(A["price"] - X["price"])
    AB = abs(B["price"] - A["price"])
    BC = abs(C["price"] - B["price"])
    CD = abs(D["price"] - C["price"])

    if XA == 0 or AB == 0:
        return None

    AB_XA = AB / XA
    BC_AB = BC / AB if AB > 0 else 0
    AD_XA = abs(D["price"] - A["price"]) / XA

    if (
        0.382 <= AB_XA <= 0.5 and
        0.382 <= BC_AB <= 0.886 and
        _check_ratio(AD_XA, 0.886, 0.1)
    ):
        direction = "bullish" if D["price"] < X["price"] else "bearish"
        return {
            "pattern": "Bat",
            "direction": direction,
            "points": {"X": X, "A": A, "B": B, "C": C, "D": D},
            "completion": float(D["price"]),
            "target1": float(D["price"] + (0.382 * AB)) if direction == "bullish" else float(D["price"] - (0.382 * AB)),
            "target2": float(D["price"] + (0.618 * XA)) if direction == "bullish" else float(D["price"] - (0.618 * XA)),
            "stop_loss": float(X["price"]),
            "confidence": _pattern_confidence([
                abs(AB_XA - 0.441) / 0.441,  # midpoint of the 0.382-0.5 valid range
                abs(AD_XA - 0.886) / 0.886,
            ])
        }

    return None


def detect_butterfly(df: pd.DataFrame) -> Optional[dict]:
    """
    Detect Butterfly harmonic pattern.
    Ratios: B=0.786 of XA, C=0.382-0.886 of AB, D=1.272-1.618 of XA
    """
    swing_highs, swing_lows = _find_swing_points(df)

    if len(swing_highs) < 3 or len(swing_lows) < 3:
        return None

    all_swings = sorted(
        [{"type": "high", **h} for h in swing_highs[-3:]] +
        [{"type": "low", **l} for l in swing_lows[-3:]],
        key=lambda x: x["index"]
    )

    if len(all_swings) < 5:
        return None

    X, A, B, C, D = all_swings[-5], all_swings[-4], all_swings[-3], all_swings[-2], all_swings[-1]

    XA = abs(A["price"] - X["price"])
    AB = abs(B["price"] - A["price"])
    BC = abs(C["price"] - B["price"])

    if XA == 0 or AB == 0:
        return None

    AB_XA = AB / XA
    BC_AB = BC / AB if AB > 0 else 0
    AD_XA = abs(D["price"] - A["price"]) / XA

    if (
        _check_ratio(AB_XA, 0.786, 0.1) and
        0.382 <= BC_AB <= 0.886 and
        1.272 <= AD_XA <= 1.618
    ):
        direction = "bullish" if D["price"] < A["price"] else "bearish"
        return {
            "pattern": "Butterfly",
            "direction": direction,
            "points": {"X": X, "A": A, "B": B, "C": C, "D": D},
            "completion": float(D["price"]),
            "target1": float(A["price"]),
            "target2": float(X["price"]),
            "stop_loss": float(D["price"] * (0.985 if direction == "bullish" else 1.015)),
            "confidence": _pattern_confidence([
                abs(AB_XA - 0.786) / 0.786,
                abs(AD_XA - 1.445) / 1.445,  # midpoint of the 1.272-1.618 valid range
            ])
        }

    return None


def detect_abcd(df: pd.DataFrame) -> Optional[dict]:
    """
    Detect the ABCD harmonic pattern (simplified 4-point pattern, no X leg).
    BC retraces AB by ~0.618, and CD projects BC by ~1.272-1.618 (AB≈CD in length).
    """
    swing_highs, swing_lows = _find_swing_points(df)

    if len(swing_highs) < 2 or len(swing_lows) < 2:
        return None

    all_swings = sorted(
        [{"type": "high", **h} for h in swing_highs[-2:]] +
        [{"type": "low", **l} for l in swing_lows[-2:]],
        key=lambda x: x["index"]
    )

    if len(all_swings) < 4:
        return None

    A, B, C, D = all_swings[-4:]

    AB = abs(B["price"] - A["price"])
    BC = abs(C["price"] - B["price"])
    CD = abs(D["price"] - C["price"])

    if AB == 0 or BC == 0:
        return None

    BC_AB = BC / AB
    CD_BC = CD / BC

    if not (0.382 <= BC_AB <= 0.886 and 1.13 <= CD_BC <= 1.618):
        return None

    if A["type"] == "high" and B["type"] == "low" and C["type"] == "high" and D["type"] == "low":
        direction = "bullish"
    elif A["type"] == "low" and B["type"] == "high" and C["type"] == "low" and D["type"] == "high":
        direction = "bearish"
    else:
        return None

    return {
        "pattern": "ABCD",
        "direction": direction,
        "points": {"A": A, "B": B, "C": C, "D": D},
        "completion": float(D["price"]),
        "target1": float(D["price"] + (0.618 * CD)) if direction == "bullish" else float(D["price"] - (0.618 * CD)),
        "target2": float(D["price"] + (1.0 * CD)) if direction == "bullish" else float(D["price"] - (1.0 * CD)),
        "stop_loss": float(C["price"]),
        "confidence": _pattern_confidence([
            abs(BC_AB - 0.618) / 0.618,
            abs(CD_BC - 1.272) / 1.272,
        ])
    }


def run_harmonic_analysis(df: pd.DataFrame) -> dict:
    """Run all harmonic pattern detection and Fibonacci analysis."""
    patterns = []

    gartley = detect_gartley(df)
    if gartley:
        patterns.append(gartley)

    bat = detect_bat(df)
    if bat:
        patterns.append(bat)

    butterfly = detect_butterfly(df)
    if butterfly:
        patterns.append(butterfly)

    abcd = detect_abcd(df)
    if abcd:
        patterns.append(abcd)

    # Calculate Fibonacci from recent swing
    highs = df["high"].values
    lows = df["low"].values
    recent_high = float(max(highs[-50:]))
    recent_low = float(min(lows[-50:]))
    last_close = float(df["close"].iloc[-1])

    trend = "uptrend" if last_close > (recent_high + recent_low) / 2 else "downtrend"
    fibonacci = get_fibonacci_levels(recent_high, recent_low, trend)

    # Bias from patterns
    bullish = sum(1 for p in patterns if p["direction"] == "bullish")
    bearish = sum(1 for p in patterns if p["direction"] == "bearish")

    return {
        "patterns": patterns,
        "fibonacci": fibonacci,
        "bias": "bullish" if bullish > bearish else ("bearish" if bearish > bullish else "neutral"),
        "patterns_count": len(patterns)
    }
