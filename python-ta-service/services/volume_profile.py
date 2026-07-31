import pandas as pd
import numpy as np


def calculate_volume_profile(df: pd.DataFrame, bins: int = 50) -> dict:
    """
    Calculate Volume Profile (POC, VAH, VAL, HVN, LVN).
    Distributes volume across price bins to find key levels.
    """
    price_min = float(df["low"].min())
    price_max = float(df["high"].max())

    if price_max == price_min:
        return {}

    price_range = price_max - price_min
    bin_size = price_range / bins

    # Build volume profile
    profile = {}
    for i in range(bins):
        bin_low = price_min + (i * bin_size)
        bin_high = bin_low + bin_size
        bin_mid = (bin_low + bin_high) / 2
        profile[round(bin_mid, 8)] = 0.0

    # Distribute each candle's volume across bins it touches
    for _, row in df.iterrows():
        candle_low = float(row["low"])
        candle_high = float(row["high"])
        candle_volume = float(row["volume"])

        for price_level, _ in profile.items():
            if candle_low <= price_level <= candle_high:
                candle_range = candle_high - candle_low
                if candle_range > 0:
                    bin_volume = candle_volume * (bin_size / candle_range)
                else:
                    bin_volume = candle_volume / bins
                profile[price_level] += bin_volume

    if not profile:
        return {}

    total_volume = sum(profile.values())

    # Point of Control (POC) — price level with highest volume
    poc_price = max(profile, key=profile.get)
    poc_volume = profile[poc_price]

    # Value Area: 70% of total volume centered around POC
    sorted_levels = sorted(profile.items(), key=lambda x: x[1], reverse=True)
    value_area_volume = 0
    value_area_levels = []
    target = total_volume * 0.70

    for price, vol in sorted_levels:
        value_area_levels.append(price)
        value_area_volume += vol
        if value_area_volume >= target:
            break

    vah = float(max(value_area_levels)) if value_area_levels else None
    val = float(min(value_area_levels)) if value_area_levels else None

    # High Volume Nodes (HVN) — above average volume
    avg_volume = total_volume / bins
    hvn = [
        {"price": float(p), "volume": float(v)}
        for p, v in sorted_levels
        if v > avg_volume * 1.5
    ][:5]

    # Low Volume Nodes (LVN) — below average volume (gap areas)
    lvn = [
        {"price": float(p), "volume": float(v)}
        for p, v in sorted_levels
        if v < avg_volume * 0.3
    ][:5]

    # Profile chart data (for visualization)
    profile_chart = [
        {"price": float(p), "volume": float(v), "percent": float(v / total_volume * 100)}
        for p, v in sorted(profile.items(), key=lambda x: x[0])
    ]

    return {
        "poc": float(poc_price),
        "poc_volume": float(poc_volume),
        "vah": vah,
        "val": val,
        "value_area_percent": 70,
        "hvn": hvn,
        "lvn": lvn,
        "profile": profile_chart,
        "total_volume": float(total_volume),
        "bins": bins
    }


def detect_volume_anomalies(df: pd.DataFrame) -> dict:
    """Detect unusual volume spikes that may indicate smart money activity."""
    avg_volume = df["volume"].mean()
    std_volume = df["volume"].std()
    threshold = avg_volume + (2 * std_volume)

    anomalies = []
    for i, row in df.tail(20).iterrows():
        if row["volume"] > threshold:
            direction = "bullish" if row["close"] > row["open"] else "bearish"
            anomalies.append({
                "timestamp": str(row["open_time"]),
                "volume": float(row["volume"]),
                "ratio": float(row["volume"] / avg_volume),
                "direction": direction,
                "price": float(row["close"])
            })

    return {
        "anomalies": anomalies,
        "avg_volume": float(avg_volume),
        "threshold": float(threshold)
    }


def run_volume_profile_analysis(df: pd.DataFrame) -> dict:
    """Run complete volume profile analysis."""
    vp = calculate_volume_profile(df)
    anomalies = detect_volume_anomalies(df)

    # Determine bias based on current price vs POC/VAH/VAL
    last_close = float(df["close"].iloc[-1])
    bias = "neutral"

    if vp.get("poc") and vp.get("vah") and vp.get("val"):
        if last_close > vp["vah"]:
            bias = "bullish"  # Price above value area
        elif last_close < vp["val"]:
            bias = "bearish"  # Price below value area
        elif last_close > vp["poc"]:
            bias = "slightly_bullish"
        else:
            bias = "slightly_bearish"

    return {
        "volume_profile": vp,
        "anomalies": anomalies,
        "bias": bias,
        "key_levels": {
            "poc": vp.get("poc"),
            "vah": vp.get("vah"),
            "val": vp.get("val")
        }
    }
