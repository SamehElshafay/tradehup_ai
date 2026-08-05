"""
Data-driven search for the Classical/SMC/Harmonic/Volume weighting used by
compute_overall_bias(). The current 30/40/20/10 split was hand-picked and the
backtest showed it performs BELOW a random 50/50 guess (~16.6% vs ~21.7% real
win rate across 5 symbols). This walks the same historical windows once,
records each school's raw categorical bias + signal counts, then replays many
weight combinations cheaply against that cached data to find the combination
that actually maximizes real forward accuracy — instead of another guess.
"""
from itertools import product

from services.binance_fetcher import fetch_ohlcv
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis


def _collect_features(symbols: list, interval: str, limit: int, horizon: int, warmup: int) -> list:
    """Run each school ONCE per point per symbol and cache the raw outputs,
    so weight combinations can be replayed cheaply without re-running analysis."""
    points = []
    for symbol in symbols:
        df = fetch_ohlcv(symbol, "binance", interval, min(limit, 1000))
        if df is None or len(df) < warmup + horizon + 10:
            continue
        n = len(df)
        for i in range(warmup, n - horizon):
            window = df.iloc[: i + 1]
            try:
                classical = run_classical_analysis(window)
                smc = run_smc_analysis(window)
                harmonic = run_harmonic_analysis(window)
                volume = run_volume_profile_analysis(window)
            except Exception:
                continue

            entry_price = float(window["close"].iloc[-1])
            future_price = float(df["close"].iloc[i + horizon])
            move_pct = (future_price - entry_price) / entry_price * 100

            points.append({
                "symbol": symbol,
                "classical_bias": classical["bias"],
                "classical_bull": classical.get("bullish_signals", 0),
                "classical_bear": classical.get("bearish_signals", 0),
                "classical_trend": classical.get("moving_averages", {}).get("trend", "neutral"),
                "smc_bias": smc["bias"],
                "harmonic_bias": harmonic["bias"],
                "volume_bias": volume["bias"],
                "move_pct": move_pct,
            })
    return points


def _bias_for_weights(point: dict, w_classical: int, w_smc: int, w_harmonic: int, w_volume: int) -> tuple:
    scores = {"bullish": 0.0, "bearish": 0.0}

    if point["classical_bias"] == "bullish":
        scores["bullish"] += w_classical * (point["classical_bull"] / max(point["classical_bull"] + point["classical_bear"], 1))
    elif point["classical_bias"] == "bearish":
        scores["bearish"] += w_classical * (point["classical_bear"] / max(point["classical_bull"] + point["classical_bear"], 1))

    if point["smc_bias"] == "bullish":
        scores["bullish"] += w_smc
    elif point["smc_bias"] == "bearish":
        scores["bearish"] += w_smc

    if point["harmonic_bias"] == "bullish":
        scores["bullish"] += w_harmonic
    elif point["harmonic_bias"] == "bearish":
        scores["bearish"] += w_harmonic

    if point["volume_bias"] in ("bullish", "slightly_bullish"):
        scores["bullish"] += w_volume
    elif point["volume_bias"] in ("bearish", "slightly_bearish"):
        scores["bearish"] += w_volume

    bias = "bullish" if scores["bullish"] > scores["bearish"] else "bearish"
    if point["classical_trend"] == "strong_downtrend" and point["smc_bias"] != "bullish":
        bias = "bearish"
    confidence = int(max(scores["bullish"], scores["bearish"]))
    return bias, confidence


def search_weights(
    symbols: list,
    interval: str = "15m",
    limit: int = 500,
    horizon: int = 6,
    min_move_pct: float = 0.3,
    warmup: int = 200,
    step: int = 10,
) -> dict:
    points = _collect_features(symbols, interval, limit, horizon, warmup)
    if not points:
        raise ValueError("No usable historical points collected")

    best = None
    results = []
    # Search weight combos in steps of `step` that sum to 100.
    for w_classical, w_smc, w_harmonic in product(range(0, 101, step), repeat=3):
        w_volume = 100 - w_classical - w_smc - w_harmonic
        if w_volume < 0 or w_volume > 100:
            continue

        wins = 0
        total = 0
        for p in points:
            bias, _ = _bias_for_weights(p, w_classical, w_smc, w_harmonic, w_volume)
            correct = (p["move_pct"] >= min_move_pct) if bias == "bullish" else (p["move_pct"] <= -min_move_pct)
            total += 1
            if correct:
                wins += 1

        win_rate = round(wins / total * 100, 2) if total else 0
        entry = {
            "weights": {"classical": w_classical, "smc": w_smc, "harmonic": w_harmonic, "volume": w_volume},
            "real_win_rate": win_rate,
            "sample_size": total,
        }
        results.append(entry)
        if best is None or win_rate > best["real_win_rate"]:
            best = entry

    results.sort(key=lambda r: r["real_win_rate"], reverse=True)
    current_formula = next(
        (r for r in results if r["weights"] == {"classical": 30, "smc": 40, "harmonic": 20, "volume": 10}),
        None,
    )

    return {
        "symbols": symbols,
        "interval": interval,
        "total_points": len(points),
        "current_formula_30_40_20_10": current_formula,
        "best_weights": best,
        "top_10": results[:10],
    }
