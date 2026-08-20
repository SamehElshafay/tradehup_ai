"""
Tests whether "price is inside a bearish Order Block" actually predicts worse
outcomes for BULLISH-bias setups — the hard rule in validateAndClampRecommendation
(never allow BUY when price is inside a bearish OB) and in the AI prompt both
assume it does. A tiny (n=8, one symbol) real-AI test suggested the AI applies
this rule so aggressively it WAITed on every real winner in the sample — but
that sample is too small to trust. This replays the same free, large-sample
Python backtest to check the underlying premise directly: among all bullish-
bias signals, does being inside a bearish OB actually correlate with a lower
real win rate?
"""
from services.binance_fetcher import fetch_ohlcv
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis
from services.bias_combiner import compute_overall_bias
from services.trade_constraints import get_constraints, get_expiry_candles
from services.trade_optimizer import _simulate


def _price_inside_bearish_ob(price: float, smc: dict) -> bool:
    for ob in smc.get("order_blocks", []) or []:
        if (ob.get("type") or "").lower() != "bearish":
            continue
        top = float(ob.get("top") or 0)
        bottom = float(ob.get("bottom") or 0)
        if bottom > 0 and top > 0 and bottom <= price <= top:
            return True
    return False


def validate_ob_rule(
    symbols: list,
    interval: str = "15m",
    limit: int = 500,
    warmup: int = 200,
    tp_mult: float = 0.5,
    expiry_mult: int = 2,
    sl_mult: float = 1.5,
) -> dict:
    constraints = get_constraints(interval)
    expiry_candles = int(get_expiry_candles(interval) * expiry_mult)

    inside_ob, outside_ob = [], []
    for symbol in symbols:
        df = fetch_ohlcv(symbol, "binance", interval, min(limit, 1000))
        if df is None or len(df) < warmup + expiry_candles + 10:
            continue
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

            bias, confidence, _ = compute_overall_bias(classical, smc, harmonic, volume)
            if bias != "bullish":
                continue  # the rule only ever blocks BUY, so only bullish signals are relevant

            entry = float(window["close"].iloc[-1])
            atr = float(classical.get("atr", {}).get("current") or 0)
            point = {
                "bias": bias, "entry": entry, "atr": atr,
                "future_highs": df["high"].iloc[i + 1: i + 1 + expiry_candles].tolist(),
                "future_lows": df["low"].iloc[i + 1: i + 1 + expiry_candles].tolist(),
            }
            outcome, pnl = _simulate(point, constraints["sl_ratio"], constraints["tp_step"], sl_mult, tp_mult, expiry_candles)
            record = {"symbol": symbol, "outcome": outcome, "pnl": pnl}

            if _price_inside_bearish_ob(entry, smc):
                inside_ob.append(record)
            else:
                outside_ob.append(record)

    def _stats(records: list) -> dict:
        if not records:
            return {"sample_size": 0}
        wins = sum(1 for r in records if r["outcome"].startswith("hit_tp"))
        losses = sum(1 for r in records if r["outcome"] == "hit_sl")
        resolved = wins + losses
        return {
            "sample_size": len(records),
            "resolved": resolved,
            "resolution_rate_pct": round(resolved / len(records) * 100, 1),
            "win_rate_of_resolved_pct": round(wins / resolved * 100, 1) if resolved else None,
        }

    return {
        "symbols": symbols,
        "interval": interval,
        "inside_bearish_ob": _stats(inside_ob),
        "outside_bearish_ob": _stats(outside_ob),
    }
