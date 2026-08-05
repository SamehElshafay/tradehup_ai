"""
Walk-forward validation of the deterministic (non-AI) analysis engine.

For each historical point in time, this re-runs the exact same Classical +
SMC + Harmonic + Volume analysis /analyze would run "live" at that moment
(using only candles available up to that point), then looks forward a fixed
number of candles to see whether price actually moved in the predicted
direction. Aggregating that by confidence bucket answers a concrete
question: does a stated confidence of e.g. 70-79% actually correspond to a
real win rate anywhere near 70-79%, or is the score not predictive at all?

This is deliberately independent of the AI — it only tests the deterministic
Python scoring (classical/SMC/harmonic/volume), which is the layer both the
AI prompt and the PreTradeFilterService are built on top of.
"""
from services.binance_fetcher import fetch_ohlcv
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis
from services.bias_combiner import compute_overall_bias
from services.trade_constraints import get_constraints, get_expiry_candles


def run_backtest(
    symbol: str,
    exchange: str = "binance",
    interval: str = "15m",
    limit: int = 1000,
    horizon: int = 6,
    min_move_pct: float = 0.3,
    warmup: int = 200,
) -> dict:
    """
    Args:
        horizon: how many candles ahead to check the outcome (e.g. 6 candles
                 on 15m = 1.5h forward).
        min_move_pct: minimum % move in the predicted direction to count as
                 "correct" — filters out noise where price barely moved.
        warmup: candles needed before indicators are stable enough to trust
                 (EMA200 etc. need real history, not just enough to not crash).
    """
    df = fetch_ohlcv(symbol, exchange, interval, min(limit, 1000))
    if df is None or len(df) < warmup + horizon + 10:
        raise ValueError(
            f"Not enough historical data for a meaningful backtest "
            f"(have {0 if df is None else len(df)} candles, need at least {warmup + horizon + 10})"
        )

    records = []
    n = len(df)
    for i in range(warmup, n - horizon):
        window = df.iloc[: i + 1]
        try:
            classical = run_classical_analysis(window)
            smc = run_smc_analysis(window)
            harmonic = run_harmonic_analysis(window)
            volume = run_volume_profile_analysis(window)
        except Exception:
            # Some indicators can throw on pathological windows (e.g. all-flat
            # candles) — skip that point rather than aborting the whole backtest.
            continue

        bias, confidence = compute_overall_bias(classical, smc, harmonic, volume)
        if bias not in ("bullish", "bearish"):
            continue

        entry_price = float(window["close"].iloc[-1])
        future_price = float(df["close"].iloc[i + horizon])
        move_pct = (future_price - entry_price) / entry_price * 100

        correct = (move_pct >= min_move_pct) if bias == "bullish" else (move_pct <= -min_move_pct)

        records.append({
            "time": str(window["open_time"].iloc[-1]),
            "bias": bias,
            "confidence": confidence,
            "entry_price": entry_price,
            "future_price": future_price,
            "move_pct": round(move_pct, 3),
            "correct": correct,
        })

    return _summarize(records, symbol, interval, horizon, min_move_pct)


def run_realistic_backtest(
    symbol: str,
    exchange: str = "binance",
    interval: str = "15m",
    limit: int = 1000,
    warmup: int = 200,
) -> dict:
    """
    Same walk-forward replay as run_backtest(), but instead of a simple "moved
    X% within N candles" proxy, this simulates an actual trade: TP1/TP2/TP3/SL
    computed with the SAME tp_step/sl_ratio/ATR-gate rules AnalysisController
    uses (services/trade_constraints.py mirrors the PHP constants), then walks
    candle-by-candle checking SL first (pessimistic, same as TradeTrackerService)
    until SL, a TP, or the timeframe's expiry window is hit. This gives a real
    win-rate number directly comparable to actual paper-trade performance,
    instead of an abstract price-movement threshold.
    """
    df = fetch_ohlcv(symbol, exchange, interval, min(limit, 1000))
    expiry_candles = get_expiry_candles(interval)
    if df is None or len(df) < warmup + expiry_candles + 10:
        raise ValueError(
            f"Not enough historical data for a meaningful backtest "
            f"(have {0 if df is None else len(df)} candles, need at least {warmup + expiry_candles + 10})"
        )

    constraints = get_constraints(interval)
    records = []
    n = len(df)

    for i in range(warmup, n - expiry_candles):
        window = df.iloc[: i + 1]
        try:
            classical = run_classical_analysis(window)
            smc = run_smc_analysis(window)
            harmonic = run_harmonic_analysis(window)
            volume = run_volume_profile_analysis(window)
        except Exception:
            continue

        bias, confidence = compute_overall_bias(classical, smc, harmonic, volume)
        if bias not in ("bullish", "bearish"):
            continue

        entry = float(window["close"].iloc[-1])
        atr = float(classical.get("atr", {}).get("current") or 0)
        is_buy = bias == "bullish"

        sl_dist = entry * constraints["sl_ratio"]
        # ATR/SL gate: widen SL if tighter than 1.5x ATR (mirrors validateAndClampRecommendation)
        if atr > 0:
            sl_dist = max(sl_dist, atr * 1.5)
        tp_step_dist = entry * constraints["tp_step"]

        sl = entry - sl_dist if is_buy else entry + sl_dist
        tp1 = entry + tp_step_dist if is_buy else entry - tp_step_dist
        tp2 = entry + tp_step_dist * 2 if is_buy else entry - tp_step_dist * 2
        tp3 = entry + tp_step_dist * 3 if is_buy else entry - tp_step_dist * 3

        outcome = "expired"
        for j in range(i + 1, min(i + 1 + expiry_candles, n)):
            high = float(df["high"].iloc[j])
            low = float(df["low"].iloc[j])

            hit_sl = (low <= sl) if is_buy else (high >= sl)
            if hit_sl:
                outcome = "hit_sl"
                break

            if is_buy:
                if high >= tp3: outcome = "hit_tp3"; break
                if high >= tp2: outcome = "hit_tp2"
                elif high >= tp1 and outcome == "expired": outcome = "hit_tp1"
            else:
                if low <= tp3: outcome = "hit_tp3"; break
                if low <= tp2: outcome = "hit_tp2"
                elif low <= tp1 and outcome == "expired": outcome = "hit_tp1"

        records.append({
            "time": str(window["open_time"].iloc[-1]),
            "bias": bias,
            "confidence": confidence,
            "entry": entry,
            "sl": sl,
            "outcome": outcome,
        })

    return _summarize_realistic(records, symbol, interval)


def _summarize_realistic(records: list, symbol: str, interval: str) -> dict:
    if not records:
        return {"symbol": symbol, "interval": interval, "sample_size": 0, "outcomes": {}, "buckets": []}

    outcome_counts = {}
    for r in records:
        outcome_counts[r["outcome"]] = outcome_counts.get(r["outcome"], 0) + 1

    wins = sum(v for k, v in outcome_counts.items() if k.startswith("hit_tp"))
    losses = outcome_counts.get("hit_sl", 0)
    resolved = wins + losses

    bucket_ranges = [(0, 50), (50, 60), (60, 70), (70, 80), (80, 101)]
    buckets = []
    for lo, hi in bucket_ranges:
        subset = [r for r in records if lo <= r["confidence"] < hi]
        if not subset:
            continue
        sub_wins = sum(1 for r in subset if r["outcome"].startswith("hit_tp"))
        sub_losses = sum(1 for r in subset if r["outcome"] == "hit_sl")
        sub_resolved = sub_wins + sub_losses
        buckets.append({
            "confidence_range": f"{lo}-{min(hi, 100)}%",
            "sample_size": len(subset),
            "resolved": sub_resolved,
            "real_win_rate_of_resolved": round(sub_wins / sub_resolved * 100, 1) if sub_resolved else None,
        })

    return {
        "symbol": symbol,
        "interval": interval,
        "sample_size": len(records),
        "outcomes": outcome_counts,
        "real_win_rate_of_resolved": round(wins / resolved * 100, 1) if resolved else None,
        "resolved_pct_of_total": round(resolved / len(records) * 100, 1),
        "buckets": buckets,
    }


def _summarize(records: list, symbol: str, interval: str, horizon: int, min_move_pct: float) -> dict:
    if not records:
        return {
            "symbol": symbol, "interval": interval, "horizon_candles": horizon,
            "min_move_pct": min_move_pct, "sample_size": 0,
            "overall_real_win_rate": None, "buckets": [], "recent_records": [],
        }

    bucket_ranges = [(0, 50), (50, 60), (60, 70), (70, 80), (80, 101)]
    buckets = []
    for lo, hi in bucket_ranges:
        subset = [r for r in records if lo <= r["confidence"] < hi]
        if not subset:
            continue
        wins = sum(1 for r in subset if r["correct"])
        buckets.append({
            "confidence_range": f"{lo}-{min(hi, 100)}%",
            "sample_size": len(subset),
            "real_win_rate": round(wins / len(subset) * 100, 1),
            "avg_move_pct": round(sum(r["move_pct"] for r in subset) / len(subset), 3),
        })

    overall_wins = sum(1 for r in records if r["correct"])
    bullish_n = sum(1 for r in records if r["bias"] == "bullish")
    bearish_n = sum(1 for r in records if r["bias"] == "bearish")
    return {
        "symbol": symbol,
        "interval": interval,
        "horizon_candles": horizon,
        "min_move_pct": min_move_pct,
        "sample_size": len(records),
        "overall_real_win_rate": round(overall_wins / len(records) * 100, 1),
        "bias_distribution": {"bullish": bullish_n, "bearish": bearish_n},
        "buckets": buckets,
        "recent_records": records[-50:],
    }
