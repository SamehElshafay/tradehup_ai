"""
Replays AmdCycleService's daily Accumulation-Manipulation-Distribution logic
(mirrors app/Services/AmdCycleService.php) against real historical 5m candles,
to check whether expected_direction actually predicts where price ends up.

Caveat: this only fires (at most) once per symbol per day, and only on days
where a valid tight accumulation range AND a liquidity sweep are both found —
Binance's 5m klines cap at 1000 candles (~3.5 days) per request, so even
across several symbols the sample size here is small. Treat this as an early
smoke test, not a statistically confident verdict — the honest conclusion for
a low-frequency, non-blocking (+8% bonus only) signal like this is to keep
monitoring in production rather than pretend a handful of days proves it.
"""
from datetime import datetime, timezone

import pandas as pd
import requests

from services.binance_fetcher import fetch_ohlcv


def _fetch_ohlcv_paginated(symbol: str, interval: str, days: int) -> pd.DataFrame:
    """
    fetch_ohlcv() is capped at Binance's 1000-candles-per-call limit (~3.5
    days on 5m) — this chains several calls backward via `endTime` to cover
    more days, specifically for AMD's need of many independent UTC days
    rather than fine-grained recent resolution. Bypasses the shared cache
    (one-off deep historical pull, not something live callers need cached).
    """
    url = "https://api.binance.com/api/v3/klines"
    frames = []
    end_time = None
    calls_needed = max(1, -(-days * 288 // 1000))  # 288 5m-candles/day, ceil div
    for _ in range(calls_needed):
        params = {"symbol": symbol.upper(), "interval": interval, "limit": 1000}
        if end_time:
            params["endTime"] = end_time
        resp = requests.get(url, params=params, timeout=10, verify=False)
        resp.raise_for_status()
        raw = resp.json()
        if not raw:
            break
        df = pd.DataFrame(raw, columns=[
            "open_time", "open", "high", "low", "close", "volume",
            "close_time", "quote_volume", "trades",
            "taker_buy_base", "taker_buy_quote", "ignore",
        ])
        df = df[["open_time", "open", "high", "low", "close", "volume"]].copy()
        df["open_time"] = pd.to_datetime(pd.to_numeric(df["open_time"]), unit="ms")
        for col in ["open", "high", "low", "close", "volume"]:
            df[col] = pd.to_numeric(df[col])
        frames.append(df)
        end_time = int(raw[0][0]) - 1  # next page ends right before this page's first candle
        if len(raw) < 1000:
            break

    if not frames:
        return pd.DataFrame()
    result = pd.concat(frames).drop_duplicates(subset="open_time").sort_values("open_time").reset_index(drop=True)
    return result

ACCUMULATION_START_HOUR = 0
ACCUMULATION_END_HOUR = 7
ACCUMULATION_MAX_ATR_MULTIPLE = 9  # keep in sync with AmdCycleService.php
MANIPULATION_WINDOWS = [(7, 9, "London"), (12, 13, "New York")]
RETURN_WINDOW_CANDLES = 3


def _hour(ts) -> int:
    return ts.hour if hasattr(ts, "hour") else datetime.fromtimestamp(ts, tz=timezone.utc).hour


def _filter_hour_range(day_df, start_h, end_h):
    hours = day_df["open_time"].dt.hour
    return day_df[(hours >= start_h) & (hours < end_h)]


def _detect_manipulation(day_df, acc_high, acc_low, return_window):
    day_df = day_df.reset_index(drop=True)
    for start_h, end_h, session in MANIPULATION_WINDOWS:
        window_df = _filter_hour_range(day_df, start_h, end_h)
        for idx in window_df.index:
            row = day_df.loc[idx]
            swept_high = row["high"] > acc_high
            swept_low = row["low"] < acc_low
            if not swept_high and not swept_low:
                continue
            for j in range(idx + 1, min(idx + 1 + return_window, len(day_df))):
                close = day_df.loc[j, "close"]
                if acc_low <= close <= acc_high:
                    return {
                        "direction": "swept_high" if swept_high else "swept_low",
                        "session": session,
                        "sweep_index": idx,
                        "return_index": j,
                    }
    return None


def validate_amd(symbols: list, atr_lookback: int = 200, days: int = 4) -> dict:
    from services.classical_analysis import run_classical_analysis

    signals = []
    for symbol in symbols:
        df = _fetch_ohlcv_paginated(symbol, "5m", days) if days > 4 else fetch_ohlcv(symbol, "binance", "5m", 1000)
        if df is None or len(df) < atr_lookback + 50:
            continue
        df = df.copy()
        df["date"] = df["open_time"].dt.date

        for day, day_df in df.groupby("date"):
            asian = _filter_hour_range(day_df, ACCUMULATION_START_HOUR, ACCUMULATION_END_HOUR)
            if asian.empty:
                continue
            acc_high = float(asian["high"].max())
            acc_low = float(asian["low"].min())
            acc_range = acc_high - acc_low

            # ATR from the data available up to the end of the accumulation window
            asian_end_idx = asian.index.max()
            atr_window = df.loc[: asian_end_idx].tail(atr_lookback)
            if len(atr_window) < 20:
                continue
            try:
                atr = float(run_classical_analysis(atr_window)["atr"]["current"] or 0)
            except Exception:
                continue
            if atr <= 0 or acc_range >= atr * ACCUMULATION_MAX_ATR_MULTIPLE:
                continue  # invalid accumulation, same rule as PHP

            manipulation = _detect_manipulation(day_df, acc_high, acc_low, RETURN_WINDOW_CANDLES)
            if not manipulation:
                continue

            expected_direction = "bearish" if manipulation["direction"] == "swept_high" else "bullish"

            # Outcome: where did price end up by the end of the UTC day vs the sweep return price?
            return_idx = manipulation["return_index"]
            day_df_reset = day_df.reset_index(drop=True)
            entry_price = float(day_df_reset.loc[return_idx, "close"])
            day_close = float(day_df_reset["close"].iloc[-1])
            move_pct = (day_close - entry_price) / entry_price * 100
            correct = (move_pct > 0) if expected_direction == "bullish" else (move_pct < 0)

            signals.append({
                "symbol": symbol, "date": str(day), "expected_direction": expected_direction,
                "session": manipulation["session"], "entry_price": entry_price,
                "day_close": day_close, "move_pct": round(move_pct, 3), "correct": correct,
            })

    wins = sum(1 for s in signals if s["correct"])
    return {
        "symbols": symbols,
        "sample_size": len(signals),
        "wins": wins,
        "real_win_rate_pct": round(wins / len(signals) * 100, 1) if signals else None,
        "signals": signals,
        "note": "Small sample by construction — this signal fires at most once/symbol/day and Binance 5m klines only go back ~3.5 days per request.",
    }
