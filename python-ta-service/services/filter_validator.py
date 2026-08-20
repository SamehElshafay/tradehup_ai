"""
Replays PreTradeFilterService's deterministic layers (Market Structure check
+ Pattern Agreement penalty + Minimum Confidence Gate — mirrors
app/Services/PreTradeFilterService.php) against real historical outcomes, to
answer: does this filter actually improve results, and is 65% really the
right confidence threshold — or just another guessed constant like the old
30/40/20/10 weights were?

The ATR/SL hard gate (Layer 5) isn't replayed here because the backtest
already always widens SL to >=1.5x ATR by construction (services/
trade_optimizer.py), so that gate can never fire in this simulation. Bias
Divergence (Layer 3) and AMD Cycle (Layer 6) are warning/bonus-only — they
never block — so they don't affect the PROCEED/WAIT verdict either.
"""
from services.binance_fetcher import fetch_ohlcv
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis
from services.bias_combiner import compute_overall_bias
from services.trade_constraints import get_constraints, get_expiry_candles
from services.trade_optimizer import _simulate

RANGE_EXTREME_MARGIN = 0.015
PATTERN_BASE_PENALTY = 15
PATTERN_EXTRA_PENALTY = 5


def _is_at_range_extreme(price: float, smc: dict, classical: dict) -> bool:
    if price <= 0:
        return False
    margin = price * RANGE_EXTREME_MARGIN

    liquidity_zones = smc.get("liquidity_zones") or {}
    all_zones = (liquidity_zones.get("buy_side") or []) + (liquidity_zones.get("sell_side") or [])
    for zone in all_zones:
        zp = float(zone.get("price") or 0)
        if zp > 0 and abs(price - zp) <= margin:
            return True

    for ob in smc.get("order_blocks", []) or []:
        top = float(ob.get("top") or 0)
        bottom = float(ob.get("bottom") or 0)
        if (top > 0 and abs(price - top) <= margin) or (bottom > 0 and abs(price - bottom) <= margin):
            return True

    sr = classical.get("support_resistance", {}) or {}
    for level in (sr.get("support", []) or []) + (sr.get("resistance", []) or []):
        lp = float(level.get("price") or 0)
        if lp > 0 and abs(price - lp) <= margin:
            return True

    return False


def _check_market_structure(price: float, smc: dict, classical: dict) -> bool:
    """Returns True (PROCEED) or False (WAIT)."""
    trend = (smc.get("market_structure") or {}).get("trend", "unknown")
    if trend != "ranging":
        return True
    return _is_at_range_extreme(price, smc, classical)


def _check_pattern_agreement(classical: dict, harmonic: dict) -> int:
    """Returns confidence penalty (0 if no conflict)."""
    bullish = bearish = 0
    for p in classical.get("chart_patterns", []) or []:
        d = (p.get("direction") or "").lower()
        if d == "bullish": bullish += 1
        elif d == "bearish": bearish += 1
    for p in harmonic.get("patterns", []) or []:
        d = (p.get("direction") or "").lower()
        if d == "bullish": bullish += 1
        elif d == "bearish": bearish += 1

    if bullish == 0 or bearish == 0:
        return 0
    conflict_ratio = min(bullish, bearish) / max(bullish, bearish)
    penalty = PATTERN_BASE_PENALTY + (conflict_ratio * PATTERN_EXTRA_PENALTY)
    return min(int(penalty + 0.999), 20)  # ceil, capped at 20


def validate_filter(
    symbols: list,
    interval: str = "15m",
    limit: int = 500,
    warmup: int = 200,
    confidence_thresholds: list = None,
    tp_mult: float = 0.5,
    expiry_mult: int = 2,
    sl_mult: float = 1.5,
) -> dict:
    confidence_thresholds = confidence_thresholds or [50, 55, 60, 65, 70, 75, 80]
    constraints = get_constraints(interval)
    base_expiry = get_expiry_candles(interval)
    expiry_candles = int(base_expiry * expiry_mult)

    points = []
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
            if bias not in ("bullish", "bearish"):
                continue

            price = float(window["close"].iloc[-1])
            structure_ok = _check_market_structure(price, smc, classical)
            penalty = _check_pattern_agreement(classical, harmonic)
            adjusted_confidence = max(0, confidence - penalty)

            atr = float(classical.get("atr", {}).get("current") or 0)
            future_highs = df["high"].iloc[i + 1: i + 1 + expiry_candles].tolist()
            future_lows = df["low"].iloc[i + 1: i + 1 + expiry_candles].tolist()

            points.append({
                "bias": bias, "entry": price, "atr": atr,
                "future_highs": future_highs, "future_lows": future_lows,
                "structure_ok": structure_ok, "adjusted_confidence": adjusted_confidence,
            })

    def _score(subset: list) -> dict:
        if not subset:
            return {"sample_size": 0}
        wins_pct, losses_pct = [], []
        for p in subset:
            outcome, pnl = _simulate(p, constraints["sl_ratio"], constraints["tp_step"], sl_mult, tp_mult, expiry_candles)
            if outcome == "hit_sl":
                losses_pct.append(pnl)
            elif outcome.startswith("hit_tp"):
                wins_pct.append(pnl)
        resolved = len(wins_pct) + len(losses_pct)
        win_rate = len(wins_pct) / resolved if resolved else 0
        loss_rate = len(losses_pct) / resolved if resolved else 0
        avg_win = sum(wins_pct) / len(wins_pct) if wins_pct else 0
        avg_loss = abs(sum(losses_pct) / len(losses_pct)) if losses_pct else 0
        expectancy = win_rate * avg_win - loss_rate * avg_loss
        return {
            "sample_size": len(subset),
            "resolved": resolved,
            "resolution_rate_pct": round(resolved / len(subset) * 100, 1),
            "win_rate_of_resolved_pct": round(win_rate * 100, 1),
            "expectancy_per_resolved_trade_pct": round(expectancy, 3),
            "opportunity_adjusted_score": round(expectancy * (resolved / len(subset) if subset else 0), 4),
        }

    baseline = _score(points)
    structure_filtered = _score([p for p in points if p["structure_ok"]])

    threshold_results = []
    for t in confidence_thresholds:
        subset = [p for p in points if p["structure_ok"] and p["adjusted_confidence"] >= t]
        entry = _score(subset)
        entry["confidence_threshold"] = t
        threshold_results.append(entry)

    return {
        "symbols": symbols,
        "interval": interval,
        "total_points": len(points),
        "no_filter_baseline": baseline,
        "market_structure_filter_only": structure_filtered,
        "by_confidence_threshold": threshold_results,
    }
