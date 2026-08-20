"""
Searches TP-distance / expiry-window / SL-distance multipliers for the value
that actually maximizes expectancy (expected % return per trade), not raw
win rate — a high win rate built on tiny TP1s and a huge SL is a trap: it
looks great on paper while being financially destructive (rare losses wipe
out many small wins). Expectancy = win_rate * avg_win% - loss_rate * avg_loss%,
computed only over trades that actually resolved (hit TP or SL), then scaled
by how often trades resolve at all — a combo that resolves rarely is not
useful even if its resolved-trade expectancy looks good.

Feature collection (the expensive part — classical/SMC/harmonic/volume per
point) runs ONCE per point; each multiplier combo is then replayed cheaply
against the same cached future price path.
"""
from itertools import product

from services.binance_fetcher import fetch_ohlcv
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis
from services.bias_combiner import compute_overall_bias
from services.trade_constraints import get_constraints, get_expiry_candles


def _collect_points(symbols: list, interval: str, limit: int, warmup: int, max_lookahead: int) -> list:
    points = []
    for symbol in symbols:
        df = fetch_ohlcv(symbol, "binance", interval, min(limit, 1000))
        if df is None or len(df) < warmup + max_lookahead + 10:
            continue
        n = len(df)
        for i in range(warmup, n - max_lookahead):
            window = df.iloc[: i + 1]
            try:
                classical = run_classical_analysis(window)
                smc = run_smc_analysis(window)
                harmonic = run_harmonic_analysis(window)
                volume = run_volume_profile_analysis(window)
            except Exception:
                continue

            bias, confidence, _ = compute_overall_bias(classical, smc, harmonic, volume)
            if bias not in ("bullish", "bearish"):
                continue

            entry = float(window["close"].iloc[-1])
            atr = float(classical.get("atr", {}).get("current") or 0)
            future_highs = df["high"].iloc[i + 1: i + 1 + max_lookahead].tolist()
            future_lows = df["low"].iloc[i + 1: i + 1 + max_lookahead].tolist()

            points.append({
                "symbol": symbol, "bias": bias, "confidence": confidence,
                "entry": entry, "atr": atr,
                "future_highs": future_highs, "future_lows": future_lows,
            })
    return points


def _simulate(point: dict, sl_ratio: float, tp_step: float, sl_mult: float, tp_mult: float, expiry_candles: int) -> tuple:
    """Returns (outcome, pnl_pct) for one point under one parameter combo."""
    entry = point["entry"]
    atr = point["atr"]
    is_buy = point["bias"] == "bullish"

    sl_dist = entry * sl_ratio * sl_mult
    if atr > 0:
        sl_dist = max(sl_dist, atr * 1.5)  # ATR gate always applies, same as production
    tp_dist = entry * tp_step * tp_mult

    sl = entry - sl_dist if is_buy else entry + sl_dist
    tp1 = entry + tp_dist if is_buy else entry - tp_dist
    tp2 = entry + tp_dist * 2 if is_buy else entry - tp_dist * 2
    tp3 = entry + tp_dist * 3 if is_buy else entry - tp_dist * 3

    outcome = "expired"
    n_candles = min(expiry_candles, len(point["future_highs"]))
    for j in range(n_candles):
        high = point["future_highs"][j]
        low = point["future_lows"][j]
        hit_sl = (low <= sl) if is_buy else (high >= sl)
        if hit_sl:
            return "hit_sl", -(sl_dist / entry * 100)

        if is_buy:
            if high >= tp3: return "hit_tp3", (tp_dist * 3 / entry * 100)
            if high >= tp2: outcome = "hit_tp2"
            elif high >= tp1 and outcome == "expired": outcome = "hit_tp1"
        else:
            if low <= tp3: return "hit_tp3", (tp_dist * 3 / entry * 100)
            if low <= tp2: outcome = "hit_tp2"
            elif low <= tp1 and outcome == "expired": outcome = "hit_tp1"

    if outcome == "hit_tp1":
        return outcome, tp_dist / entry * 100
    if outcome == "hit_tp2":
        return outcome, tp_dist * 2 / entry * 100
    return "expired", 0.0


def optimize_trade_params(
    symbols: list,
    interval: str = "15m",
    limit: int = 500,
    warmup: int = 200,
    tp_multipliers: list = None,
    expiry_multipliers: list = None,
    sl_multipliers: list = None,
) -> dict:
    tp_multipliers = tp_multipliers or [0.5, 0.75, 1.0, 1.5, 2.0]
    expiry_multipliers = expiry_multipliers or [1, 2, 3, 4]
    sl_multipliers = sl_multipliers or [0.75, 1.0, 1.5]

    constraints = get_constraints(interval)
    base_expiry = get_expiry_candles(interval)
    max_lookahead = base_expiry * max(expiry_multipliers)

    points = _collect_points(symbols, interval, limit, warmup, max_lookahead)
    if not points:
        raise ValueError("No usable historical points collected")

    results = []
    for tp_mult, expiry_mult, sl_mult in product(tp_multipliers, expiry_multipliers, sl_multipliers):
        expiry_candles = int(base_expiry * expiry_mult)
        wins_pct = []
        losses_pct = []
        resolved = 0
        for p in points:
            outcome, pnl_pct = _simulate(
                p, constraints["sl_ratio"], constraints["tp_step"], sl_mult, tp_mult, expiry_candles
            )
            if outcome == "hit_sl":
                resolved += 1
                losses_pct.append(pnl_pct)  # already negative
            elif outcome.startswith("hit_tp"):
                resolved += 1
                wins_pct.append(pnl_pct)

        total = len(points)
        win_n, loss_n = len(wins_pct), len(losses_pct)
        win_rate = win_n / resolved if resolved else 0
        loss_rate = loss_n / resolved if resolved else 0
        avg_win = sum(wins_pct) / win_n if win_n else 0
        avg_loss = abs(sum(losses_pct) / loss_n) if loss_n else 0
        expectancy_per_resolved = win_rate * avg_win - loss_rate * avg_loss
        resolution_rate = resolved / total if total else 0
        # Opportunity-adjusted score: expectancy you'd actually capture per
        # point in time, accounting for how often trades resolve at all.
        score = expectancy_per_resolved * resolution_rate

        results.append({
            "tp_multiplier": tp_mult,
            "expiry_multiplier": expiry_mult,
            "sl_multiplier": sl_mult,
            "resolution_rate_pct": round(resolution_rate * 100, 1),
            "win_rate_of_resolved_pct": round(win_rate * 100, 1),
            "avg_win_pct": round(avg_win, 3),
            "avg_loss_pct": round(avg_loss, 3),
            "expectancy_per_resolved_trade_pct": round(expectancy_per_resolved, 3),
            "opportunity_adjusted_score": round(score, 4),
            "resolved_count": resolved,
        })

    results.sort(key=lambda r: r["opportunity_adjusted_score"], reverse=True)

    current = next(
        (r for r in results if r["tp_multiplier"] == 1.0 and r["expiry_multiplier"] == 1 and r["sl_multiplier"] == 1.0),
        None,
    )

    return {
        "symbols": symbols,
        "interval": interval,
        "total_points": len(points),
        "current_settings": current,
        "best": results[0] if results else None,
        "top_10": results[:10],
    }
