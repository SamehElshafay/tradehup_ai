import pandas as pd
import numpy as np
from typing import Optional


def detect_bos_choch(df: pd.DataFrame) -> dict:
    """
    Detect Break of Structure (BOS) and Change of Character (CHoCH).
    BOS = market continues in the same direction after breaking a swing high/low
    CHoCH = market breaks structure in the opposite direction (trend change signal)
    """
    highs = df["high"].values
    lows = df["low"].values
    closes = df["close"].values
    times = df["open_time"].tolist()

    swing_highs = []
    swing_lows = []

    # Find swing highs and lows
    for i in range(2, len(df) - 2):
        if highs[i] > highs[i - 1] and highs[i] > highs[i - 2] and highs[i] > highs[i + 1] and highs[i] > highs[i + 2]:
            swing_highs.append({"index": i, "price": float(highs[i]), "timestamp": str(times[i])})
        if lows[i] < lows[i - 1] and lows[i] < lows[i - 2] and lows[i] < lows[i + 1] and lows[i] < lows[i + 2]:
            swing_lows.append({"index": i, "price": float(lows[i]), "timestamp": str(times[i])})

    bos_events = []
    choch_events = []

    # Determine trend and detect breaks
    if len(swing_highs) >= 2 and len(swing_lows) >= 2:
        last_sh = swing_highs[-1]
        prev_sh = swing_highs[-2]
        last_sl = swing_lows[-1]
        prev_sl = swing_lows[-2]

        last_close = float(closes[-1])

        # Uptrend: Higher Highs and Higher Lows
        is_uptrend = last_sh["price"] > prev_sh["price"] and last_sl["price"] > prev_sl["price"]
        # Downtrend: Lower Highs and Lower Lows
        is_downtrend = last_sh["price"] < prev_sh["price"] and last_sl["price"] < prev_sl["price"]

        # BOS in uptrend: close breaks above previous swing high
        if is_uptrend and last_close > prev_sh["price"]:
            bos_events.append({
                "type": "BOS",
                "direction": "bullish",
                "price": prev_sh["price"],
                "timestamp": str(times[-1]),
                "description": "Bullish Break of Structure"
            })

        # BOS in downtrend: close breaks below previous swing low
        if is_downtrend and last_close < prev_sl["price"]:
            bos_events.append({
                "type": "BOS",
                "direction": "bearish",
                "price": prev_sl["price"],
                "timestamp": str(times[-1]),
                "description": "Bearish Break of Structure"
            })

        # CHoCH: break against the trend
        if is_downtrend and last_close > last_sh["price"]:
            choch_events.append({
                "type": "CHoCH",
                "direction": "bullish",
                "price": last_sh["price"],
                "timestamp": str(times[-1]),
                "description": "Change of Character — Potential Reversal to Upside"
            })

        if is_uptrend and last_close < last_sl["price"]:
            choch_events.append({
                "type": "CHoCH",
                "direction": "bearish",
                "price": last_sl["price"],
                "timestamp": str(times[-1]),
                "description": "Change of Character — Potential Reversal to Downside"
            })

    trend = "ranging"
    if len(swing_highs) >= 2 and len(swing_lows) >= 2:
        last_sh = swing_highs[-1]
        prev_sh = swing_highs[-2]
        last_sl = swing_lows[-1]
        prev_sl = swing_lows[-2]
        if last_sh["price"] > prev_sh["price"] and last_sl["price"] > prev_sl["price"]:
            trend = "bullish"
        elif last_sh["price"] < prev_sh["price"] and last_sl["price"] < prev_sl["price"]:
            trend = "bearish"

    return {
        "trend": trend,
        "swing_highs": swing_highs[-5:],
        "swing_lows": swing_lows[-5:],
        "bos": bos_events,
        "choch": choch_events,
    }


def detect_order_blocks(df: pd.DataFrame) -> list:
    """
    Detect Order Blocks (OB).
    Bullish OB: last bearish candle before a strong bullish move
    Bearish OB: last bullish candle before a strong bearish move
    Only returns active (unmitigated) Order Blocks.
    """
    order_blocks = []
    opens = df["open"].values
    closes = df["close"].values
    highs = df["high"].values
    lows = df["low"].values
    times = df["open_time"].tolist()

    # Detect time interval from dataframe to scale threshold dynamically
    threshold = 0.01  # Default for daily/weekly/large timeframes
    if len(df) >= 2:
        time_diff = (df["open_time"].iloc[1] - df["open_time"].iloc[0]).total_seconds()
        if time_diff <= 300:     # 5m or lower
            threshold = 0.0015   # 0.15%
        elif time_diff <= 900:   # 15m or lower
            threshold = 0.0025   # 0.25%
        elif time_diff <= 3600:  # 1h or lower
            threshold = 0.0045   # 0.45%
        elif time_diff <= 14400: # 4h or lower
            threshold = 0.007    # 0.7%

    for i in range(5, len(df) - 4):
        # Check for strong move after
        future_change = (closes[i + 3] - closes[i]) / closes[i]

        # Bullish OB: bearish candle followed by strong bullish move (>threshold)
        if closes[i] < opens[i] and future_change > threshold:
            top = float(opens[i])
            bottom = float(closes[i])
            
            # Check if mitigated (filled) by any subsequent candle after the strong move completes (index i + 4)
            mitigated = False
            for j in range(i + 4, len(df)):
                if lows[j] <= bottom:
                    mitigated = True
                    break
            
            if not mitigated:
                order_blocks.append({
                    "type": "bullish",
                    "top": top,
                    "bottom": bottom,
                    "timestamp": int(times[i].timestamp()),
                    "index": int(i),
                    "description": "Bullish Order Block"
                })

        # Bearish OB: bullish candle followed by strong bearish move (<-threshold)
        if closes[i] > opens[i] and future_change < -threshold:
            top = float(closes[i])
            bottom = float(opens[i])
            
            # Check if mitigated (filled) by any subsequent candle after the strong move completes (index i + 4)
            mitigated = False
            for j in range(i + 4, len(df)):
                if highs[j] >= top:
                    mitigated = True
                    break
            
            if not mitigated:
                order_blocks.append({
                    "type": "bearish",
                    "top": top,
                    "bottom": bottom,
                    "timestamp": int(times[i].timestamp()),
                    "index": int(i),
                    "description": "Bearish Order Block"
                })

    # Return only the most recent and relevant OBs
    return order_blocks[-6:]


def detect_fvg(df: pd.DataFrame) -> list:
    """
    Detect Fair Value Gaps (FVG / Imbalance).
    FVG exists when candle[i-1].high < candle[i+1].low (bullish)
    or candle[i-1].low > candle[i+1].high (bearish)
    Only returns active (unmitigated/unfilled) FVGs.
    """
    fvgs = []
    highs = df["high"].values
    lows = df["low"].values
    times = df["open_time"].tolist()

    for i in range(1, len(df) - 1):
        # Bullish FVG
        if highs[i - 1] < lows[i + 1]:
            gap_size = lows[i + 1] - highs[i - 1]
            top = float(lows[i + 1])
            bottom = float(highs[i - 1])
            
            # Check if mitigated (filled) by any subsequent candle
            mitigated = False
            for j in range(i + 2, len(df)):
                if lows[j] <= bottom:
                    mitigated = True
                    break
            
            if not mitigated:
                fvgs.append({
                    "type": "bullish",
                    "top": top,
                    "bottom": bottom,
                    "gap_size": float(gap_size),
                    "timestamp": int(times[i].timestamp()),
                    "description": "Bullish Fair Value Gap"
                })

        # Bearish FVG
        if lows[i - 1] > highs[i + 1]:
            gap_size = lows[i - 1] - highs[i + 1]
            top = float(lows[i - 1])
            bottom = float(highs[i + 1])
            
            # Check if mitigated (filled) by any subsequent candle
            mitigated = False
            for j in range(i + 2, len(df)):
                if highs[j] >= top:
                    mitigated = True
                    break
            
            if not mitigated:
                fvgs.append({
                    "type": "bearish",
                    "top": top,
                    "bottom": bottom,
                    "gap_size": float(gap_size),
                    "timestamp": int(times[i].timestamp()),
                    "description": "Bearish Fair Value Gap"
                })

    # Filter: return only recent and significant FVGs
    if fvgs:
        avg_price = float(df["close"].mean())
        fvgs = [f for f in fvgs if f["gap_size"] / avg_price > 0.001]  # Min 0.1% gap

    return fvgs[-8:]  # Last 8 active FVGs


def detect_liquidity_zones(df: pd.DataFrame) -> dict:
    """
    Detect liquidity pools (equal highs/lows where stop-losses cluster).
    """
    highs = df["high"].values
    lows = df["low"].values
    times = df["open_time"].tolist()
    tolerance = 0.002  # 0.2%

    equal_highs = []
    equal_lows = []

    for i in range(len(df)):
        for j in range(i + 1, min(i + 20, len(df))):
            # Equal Highs (buy-side liquidity)
            if abs(highs[i] - highs[j]) / highs[i] <= tolerance:
                equal_highs.append({
                    "price": float((highs[i] + highs[j]) / 2),
                    "timestamp1": str(times[i]),
                    "timestamp2": str(times[j]),
                    "type": "buy_side_liquidity",
                    "description": "Equal Highs — Buy-Side Liquidity Pool"
                })
            # Equal Lows (sell-side liquidity)
            if abs(lows[i] - lows[j]) / lows[i] <= tolerance:
                equal_lows.append({
                    "price": float((lows[i] + lows[j]) / 2),
                    "timestamp1": str(times[i]),
                    "timestamp2": str(times[j]),
                    "type": "sell_side_liquidity",
                    "description": "Equal Lows — Sell-Side Liquidity Pool"
                })

    return {
        "buy_side": equal_highs[-4:],
        "sell_side": equal_lows[-4:],
    }


def run_smc_analysis(df: pd.DataFrame) -> dict:
    """Run all Smart Money Concepts analysis."""
    structure = detect_bos_choch(df)
    order_blocks = detect_order_blocks(df)
    fvgs = detect_fvg(df)
    liquidity = detect_liquidity_zones(df)
    current_price = float(df["close"].iloc[-1])

    # SMC Bias — weighted scoring based on structure breaks and SMC zones
    bullish = 0
    bearish = 0

    for bos in structure.get("bos", []):
        if bos["direction"] == "bullish":
            bullish += 2
        else:
            bearish += 2

    for choch in structure.get("choch", []):
        if choch["direction"] == "bullish":
            bullish += 3
        else:
            bearish += 3

    # Order Blocks: bullish OBs below price act as demand, bearish OBs above price as supply
    for ob in order_blocks:
        ob_mid = (ob["top"] + ob["bottom"]) / 2
        if ob["type"] == "bullish" and ob_mid < current_price:
            bullish += 1
        elif ob["type"] == "bearish" and ob_mid > current_price:
            bearish += 1

    # Fair Value Gaps: bullish FVGs below price = demand (support), bearish above = supply
    for fvg in fvgs:
        fvg_mid = (fvg["top"] + fvg["bottom"]) / 2
        if fvg["type"] == "bullish" and fvg_mid < current_price:
            bullish += 1
        elif fvg["type"] == "bearish" and fvg_mid > current_price:
            bearish += 1

    bias = "neutral"
    total = bullish + bearish
    confidence = 0
    if total > 0:
        if bullish > bearish:
            bias = "bullish"
            confidence = int((bullish / total) * 100)
        elif bearish > bullish:
            bias = "bearish"
            confidence = int((bearish / total) * 100)
        else:
            confidence = 50
    
    # Minimum confidence floor — avoid 0%
    if total > 0 and confidence == 0:
        confidence = 50

    return {
        "market_structure": structure,
        "order_blocks": order_blocks,
        "fair_value_gaps": fvgs,
        "liquidity_zones": liquidity,
        "bias": bias,
        "confidence": confidence,
        "bullish_score": bullish,
        "bearish_score": bearish
    }
