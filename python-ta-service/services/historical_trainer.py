"""
Historical Training Data Generator
====================================
Fetches real OHLCV data from Binance for multiple coins/timeframes,
runs a sliding-window technical analysis, simulates trade outcomes
(TP1/TP2/TP3 vs SL), and returns a rich labeled dataset to train the ML model.

This generates thousands of realistic training samples covering Bull, Bear
and Ranging market conditions — far more than the live paper-trade history alone.
"""

import traceback
from typing import List, Dict, Any, Tuple, Optional

import numpy as np
import pandas as pd
import pandas_ta as ta

from services.binance_fetcher import fetch_ohlcv

# ─── Configuration ─────────────────────────────────────────────────────────────

# Coins to sweep. More coins = more diverse market conditions covered.
DEFAULT_COINS = [
    "BTCUSDT", "ETHUSDT", "BNBUSDT", "SOLUSDT", "XRPUSDT",
    "ADAUSDT", "DOGEUSDT", "AVAXUSDT", "LINKUSDT", "MATICUSDT",
    "DOTUSDT", "LTCUSDT", "UNIUSDT", "ATOMUSDT", "NEARUSDT",
]

# Timeframes to sweep — covering scalp through swing
DEFAULT_TIMEFRAMES = ["15m", "1h", "4h", "1d"]

# How many candles to fetch per coin/tf (more = more training samples)
CANDLES_PER_FETCH = 1000

# Warmup candles required before indicators are stable (EMA200 needs at least 200)
WARMUP = 220

# How many candles ahead to simulate the trade outcome
HORIZON: Dict[str, int] = {
    "1m": 30, "3m": 20, "5m": 20, "15m": 16,
    "30m": 12, "1h": 10, "2h": 8, "4h": 6,
    "6h": 5, "12h": 4, "1d": 3, "3d": 2, "1w": 2,
}
DEFAULT_HORIZON = 8  # fallback

# Max SL distance as % of price (to filter out unrealistically wide SLs)
MAX_SL_PCT = 0.06   # 6 %
MIN_SL_PCT = 0.003  # 0.3 %

# ─── Indicator Helpers ─────────────────────────────────────────────────────────


def _safe_float(val, default: float = 0.0) -> float:
    try:
        if val is None or (isinstance(val, float) and np.isnan(val)):
            return default
        return float(val)
    except Exception:
        return default


def _compute_indicators(df: pd.DataFrame) -> pd.DataFrame:
    """Attach all technical indicators to a OHLCV dataframe."""
    c = df["close"]
    h = df["high"]
    l = df["low"]
    v = df["volume"]

    # RSI
    df["rsi"] = ta.rsi(c, length=14)

    # EMAs
    df["ema9"]   = ta.ema(c, length=9)
    df["ema21"]  = ta.ema(c, length=21)
    df["ema20"]  = ta.ema(c, length=20)
    df["ema50"]  = ta.ema(c, length=50)
    df["ema200"] = ta.ema(c, length=200)

    # Price Action
    df["candle_body_pct"] = abs(c - df["open"]) / (h - l).replace(0, 1e-9) * 100

    # MACD
    macd_df = ta.macd(c, fast=12, slow=26, signal=9)
    if macd_df is not None and "MACD_12_26_9" in macd_df.columns:
        df["macd"]        = macd_df["MACD_12_26_9"]
        df["macd_signal"] = macd_df["MACDs_12_26_9"]
        df["macd_hist"]   = macd_df["MACDh_12_26_9"]
    else:
        df["macd"] = df["macd_signal"] = df["macd_hist"] = np.nan

    # Bollinger Bands
    bb = ta.bbands(c, length=20, std=2)
    if bb is not None:
        df["bb_upper"] = bb.get("BBU_20_2.0", np.nan)
        df["bb_lower"] = bb.get("BBL_20_2.0", np.nan)
        df["bb_mid"]   = bb.get("BBM_20_2.0", np.nan)

    # ATR
    df["atr"] = ta.atr(h, l, c, length=14)

    # Stochastic RSI
    stoch = ta.stochrsi(c, length=14, rsi_length=14, k=3, d=3)
    if stoch is not None and len(stoch.columns) >= 2:
        cols = list(stoch.columns)
        df["stoch_k"] = stoch[cols[0]]
        df["stoch_d"] = stoch[cols[1]]

    # Volume SMA ratio
    df["vol_sma20"] = ta.sma(v, length=20)
    df["vol_ratio"] = v / df["vol_sma20"].replace(0, np.nan)

    # ADX
    adx_df = ta.adx(h, l, c, length=14)
    if adx_df is not None and "ADX_14" in adx_df.columns:
        df["adx"] = adx_df["ADX_14"]

    return df


# ─── Feature Extraction ────────────────────────────────────────────────────────


def _extract_features(row: pd.Series, action: str) -> Dict[str, Any]:
    """Convert a single OHLCV + indicator row into the feature dict expected by MLService."""
    price = _safe_float(row.get("close"))
    ema20  = _safe_float(row.get("ema20"),  price)
    ema50  = _safe_float(row.get("ema50"),  price)
    ema200 = _safe_float(row.get("ema200"), price)

    # EMA trend
    if ema20 > ema50 > ema200:
        ema_trend = "strong_uptrend"
    elif ema20 < ema50 < ema200:
        ema_trend = "strong_downtrend"
    elif ema20 > ema50:
        ema_trend = "weak_uptrend"
    else:
        ema_trend = "weak_downtrend"

    rsi = _safe_float(row.get("rsi"), 50.0)
    atr = _safe_float(row.get("atr"), 0.0)
    volatility = round((atr / price * 100), 4) if price > 0 else 0.0
    candle_body_pct = _safe_float(row.get("candle_body_pct"), 0.0)

    # SMC-like bias from EMA structure
    smc_bias = "bullish" if price > ema50 else "bearish"

    # MACD histogram direction
    macd_hist = _safe_float(row.get("macd_hist"), 0.0)

    # Stoch RSI
    stoch_k = _safe_float(row.get("stoch_k"), 50.0)

    # ADX
    adx = _safe_float(row.get("adx"), 20.0)

    # Volume ratio
    vol_ratio = _safe_float(row.get("vol_ratio"), 1.0)

    # BB position  (0 = at lower band, 1 = at upper band)
    bb_upper = _safe_float(row.get("bb_upper"), price * 1.02)
    bb_lower = _safe_float(row.get("bb_lower"), price * 0.98)
    bb_range = bb_upper - bb_lower
    bb_pos   = (price - bb_lower) / bb_range if bb_range > 0 else 0.5

    # Price distance from EMAs (normalised as %)
    pct_from_ema20  = (price - ema20)  / ema20  * 100 if ema20  > 0 else 0.0
    pct_from_ema200 = (price - ema200) / ema200 * 100 if ema200 > 0 else 0.0

    # ATR normalised as % of price — comparable across all coins (BTC vs DOGE)
    atr_pct = round((atr / price * 100), 4) if price > 0 else 0.0

    technical_metrics = {
        "rsi":          rsi,
        "ema_trend":    ema_trend,
        "volatility":   volatility,
        "smc_bias":     smc_bias,
        "order_blocks": 1 if adx > 30 else 0,   # sniper trend proxy
        "fvgs":         1 if vol_ratio > 1.5 else 0,
        # Rich features the enhanced ml_service uses
        "macd_hist":       macd_hist,
        "stoch_k":         stoch_k,
        "adx":             adx,
        "vol_ratio":       vol_ratio,
        "bb_position":     bb_pos,
        "pct_from_ema20":  pct_from_ema20,
        "pct_from_ema200": pct_from_ema200,
        "atr_pct":         atr_pct,
        "candle_body_pct": candle_body_pct,
    }

    return {
        "action":            action,
        "technical_metrics": technical_metrics,
        "risk_reward":       0.0,   # filled in after SL/TP are computed
        "confluences":       [],    # populated below
    }


# ─── Signal Detection ──────────────────────────────────────────────────────────


def _detect_signal(row: pd.Series) -> Optional[str]:
    """
    Sniper signal detector — Maximum Quality over Quantity.
    Requires absolute alignment of moving averages, strong ADX momentum,
    and high-conviction candle bodies.
    Returns 'BUY', 'SELL', or None.
    """
    rsi       = _safe_float(row.get("rsi"), 50)
    price     = _safe_float(row.get("close"))
    ema9      = _safe_float(row.get("ema9"),   price)
    ema21     = _safe_float(row.get("ema21"),  price)
    ema50     = _safe_float(row.get("ema50"),  price)
    ema200    = _safe_float(row.get("ema200"), price)
    macd_hist = _safe_float(row.get("macd_hist"), 0)
    stoch_k   = _safe_float(row.get("stoch_k"),  50)
    adx       = _safe_float(row.get("adx"),       0)
    vol_ratio = _safe_float(row.get("vol_ratio"), 1)
    candle_body_pct = _safe_float(row.get("candle_body_pct"), 0)

    if price <= 0 or ema9 <= 0 or ema21 <= 0 or ema50 <= 0 or ema200 <= 0:
        return None

    # ─── Gate 1: SNIPER Trend (ADX > 30) ─────────────────────────
    # We only trade in explosive, clear trends.
    if adx < 30:
        return None

    # ─── Gate 2: Must have above-average volume (confirms momentum) ──────────
    if vol_ratio < 1.1:
        return None

    # ─── Gate 3: Institutional Candle Body (No DOJI fakeouts) ─────────
    # The candle body must make up at least 40% of the entire candle's range.
    if candle_body_pct < 40.0:
        return None

    # ─── Trend direction (Perfect EMA Alignment) ─────────────────────────────────
    trend_up   = (ema9 > ema21) and (ema21 > ema50) and (ema50 > ema200)
    trend_down = (ema9 < ema21) and (ema21 < ema50) and (ema50 < ema200)

    if not trend_up and not trend_down:
        return None

    # ─── BUY conditions ─────
    buy_signals = [
        rsi > 50 and rsi < 65,          # RSI: momentum is up but plenty of room to grow
        macd_hist > 0,                   # MACD histogram positive
        stoch_k > 20 and stoch_k < 75,  # Stoch: not overbought or oversold
    ]
    # ─── SELL conditions ─────────────────────────────────────────────────────
    sell_signals = [
        rsi < 50 and rsi > 35,          # RSI: momentum is down but room to fall
        macd_hist < 0,                   # MACD histogram negative
        stoch_k < 80 and stoch_k > 25,  # Stoch: not oversold
    ]

    buy_score  = sum(buy_signals)
    sell_score = sum(sell_signals)

    if buy_score >= 3 and trend_up:
        return "BUY"
    if sell_score >= 3 and trend_down:
        return "SELL"
    return None


# ─── Trade Outcome Simulation ──────────────────────────────────────────────────


def _simulate_outcome(
    df: pd.DataFrame,
    entry_idx: int,
    action: str,
    atr: float,
    price: float,
    horizon: int,
) -> Tuple[str, float]:
    """
    Look ahead `horizon` candles and determine whether price hit TP1/TP2/TP3 or SL.
    SL  = entry ± 1.5 × ATR
    TP1 = entry ± 1.0 × ATR (R:R ≈ 0.67)
    TP2 = entry ± 2.0 × ATR (R:R ≈ 1.33)
    TP3 = entry ± 3.0 × ATR (R:R ≈ 2.00)
    """
    if atr <= 0 or price <= 0:
        return "Unknown", 0.0

    sl_dist = atr * 1.5
    sl_pct  = sl_dist / price

    if sl_pct > MAX_SL_PCT or sl_pct < MIN_SL_PCT:
        return "Unknown", 0.0

    if action == "BUY":
        sl  = price - sl_dist
        tp1 = price + atr * 1.0
        tp2 = price + atr * 2.0
        tp3 = price + atr * 3.0
    else:
        sl  = price + sl_dist
        tp1 = price - atr * 1.0
        tp2 = price - atr * 2.0
        tp3 = price - atr * 3.0

    rr = round(abs(tp2 - price) / abs(sl - price), 2)

    future = df.iloc[entry_idx + 1: entry_idx + 1 + horizon]
    if future.empty:
        return "Unknown", rr

    for _, frow in future.iterrows():
        high = _safe_float(frow.get("high"), price)
        low  = _safe_float(frow.get("low"),  price)

        if action == "BUY":
            if low <= sl:
                return "Failed (Hit SL)", rr
            if high >= tp3:
                return "Hit TP3 (Full Profit)", rr
            if high >= tp2:
                return "Hit TP2", rr
            if high >= tp1:
                return "Hit TP1", rr
        else:
            if high >= sl:
                return "Failed (Hit SL)", rr
            if low <= tp3:
                return "Hit TP3 (Full Profit)", rr
            if low <= tp2:
                return "Hit TP2", rr
            if low <= tp1:
                return "Hit TP1", rr

    return "Failed (Hit SL)", rr   # expired without hitting any target


# ─── Per-coin Generator ───────────────────────────────────────────────────────


def _generate_samples_for_coin(
    symbol: str,
    interval: str,
    limit: int = CANDLES_PER_FETCH,
) -> List[Dict[str, Any]]:
    """Fetch data, run indicators, detect signals, simulate outcomes."""
    samples = []
    try:
        # fetch_ohlcv returns a pandas DataFrame directly with columns:
        # open_time, open, high, low, close, volume, quote_volume
        df = fetch_ohlcv(symbol, "binance", interval, limit=limit)

        if df is None or df.empty or len(df) < WARMUP + 20:
            return []

        # Normalise column names — rename open_time → timestamp for consistency
        if "open_time" in df.columns:
            df = df.rename(columns={"open_time": "timestamp"})

        # Ensure numeric types
        for col in ["open", "high", "low", "close", "volume"]:
            if col in df.columns:
                df[col] = pd.to_numeric(df[col], errors="coerce")

        df.dropna(subset=["close"], inplace=True)
        df.reset_index(drop=True, inplace=True)

        if len(df) < WARMUP + 20:
            return []

        df = _compute_indicators(df)

        horizon = HORIZON.get(interval, DEFAULT_HORIZON)

        for i in range(WARMUP, len(df) - horizon - 1):
            row = df.iloc[i]
            action = _detect_signal(row)
            if action is None:
                continue

            price = _safe_float(row.get("close"))
            atr   = _safe_float(row.get("atr"), 0.0)

            outcome, rr = _simulate_outcome(df, i, action, atr, price, horizon)
            if outcome == "Unknown":
                continue

            feat = _extract_features(row, action)
            feat["risk_reward"] = rr
            feat["hindsight_outcome"] = outcome
            feat["coin"] = symbol
            feat["timeframe"] = interval

            # Build simple confluence list for confluences_count feature
            confluences = []
            rsi_val = _safe_float(row.get("rsi"), 50)
            if action == "BUY"  and rsi_val < 60: confluences.append("RSI not overbought")
            if action == "SELL" and rsi_val > 40: confluences.append("RSI not oversold")
            macd_hist = _safe_float(row.get("macd_hist"), 0)
            if action == "BUY"  and macd_hist > 0: confluences.append("MACD bullish")
            if action == "SELL" and macd_hist < 0: confluences.append("MACD bearish")
            if _safe_float(row.get("adx"), 0) > 25: confluences.append("ADX trending")
            if _safe_float(row.get("vol_ratio"), 1) > 1.5: confluences.append("Volume surge")
            feat["confluences"] = confluences

            samples.append(feat)

    except Exception as e:
        print(f"[HistoricalTrainer] Error on {symbol}/{interval}: {e}")
        traceback.print_exc()

    return samples


# ─── Public API ───────────────────────────────────────────────────────────────


def generate_historical_dataset(
    coins: List[str] = None,
    timeframes: List[str] = None,
    limit: int = CANDLES_PER_FETCH,
    progress_callback=None,
) -> List[Dict[str, Any]]:
    """
    Main entry point.
    Returns a flat list of labeled trade samples ready for ml_service.train().

    Args:
        coins:      list of Binance symbols (defaults to DEFAULT_COINS)
        timeframes: list of interval strings (defaults to DEFAULT_TIMEFRAMES)
        limit:      candles to fetch per coin/tf
        progress_callback: optional callable(done, total, symbol, tf) for SSE progress
    """
    coins      = coins      or DEFAULT_COINS
    timeframes = timeframes or DEFAULT_TIMEFRAMES
    total      = len(coins) * len(timeframes)
    done       = 0

    all_samples: List[Dict[str, Any]] = []

    for symbol in coins:
        for tf in timeframes:
            samples = _generate_samples_for_coin(symbol, tf, limit)
            all_samples.extend(samples)
            done += 1
            if progress_callback:
                try:
                    progress_callback(done, total, symbol, tf)
                except Exception:
                    pass
            print(f"[HistoricalTrainer] {symbol}/{tf} → {len(samples)} samples "
                  f"({done}/{total} combos done, total so far: {len(all_samples)})")

    print(f"[HistoricalTrainer] ✅ Generated {len(all_samples)} total training samples "
          f"from {len(coins)} coins × {len(timeframes)} timeframes.")

    return all_samples
