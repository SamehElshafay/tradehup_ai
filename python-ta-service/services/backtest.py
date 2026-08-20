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


def compute_deterministic_levels(
    action: str,
    price: float,
    classical: dict,
    smc: dict,
    volume: dict,
    constraints: dict,
):
    """
    Exact Python port of AnalysisController::computeDeterministicLevels() (PHP).

    Picks TP1/TP2/TP3/SL from real chart structure:
      - Classical S/R levels
      - SMC Order Block midpoints
      - SMC Fair Value Gap midpoints
      - Volume Profile (POC / VAH / VAL)

    Falls back to synthesising levels spaced 2*min_step apart when chart levels
    are not available.  Returns None if price <= 0 or risk == 0.
    """
    if price <= 0:
        return None

    sr = classical.get("support_resistance", {})
    supports    = sorted([float(s["price"]) for s in sr.get("support",    []) if s.get("price")])
    resistances = sorted([float(r["price"]) for r in sr.get("resistance", []) if r.get("price")])
    order_blocks = smc.get("order_blocks",    [])
    fvgs         = smc.get("fair_value_gaps", [])
    vp_levels    = volume.get("key_levels", {})
    poc = float(vp_levels.get("poc") or 0)
    vah = float(vp_levels.get("vah") or 0)
    val = float(vp_levels.get("val") or 0)
    atr = float((classical.get("atr") or {}).get("current") or 0)

    max_dist = price * constraints["max_tp3"]
    sl_ratio = constraints["sl_ratio"]
    min_step = price * constraints["tp_step"] * 0.5

    def build_candidates(above):
        levels = []
        src = resistances if above else supports
        for p in src:
            if above and price < p <= price + max_dist:
                levels.append(float(p))
            if not above and price - max_dist <= p < price:
                levels.append(float(p))
        for ob in order_blocks:
            bottom = float(ob.get("bottom") or 0)
            top    = float(ob.get("top")    or 0)
            mid    = (bottom + top) / 2
            if mid <= 0:
                continue
            if above and price < mid <= price + max_dist:
                levels.append(mid)
            if not above and price - max_dist <= mid < price:
                levels.append(mid)
        for fvg in fvgs:
            bottom = float(fvg.get("bottom") or 0)
            top    = float(fvg.get("top")    or 0)
            mid    = (bottom + top) / 2
            if mid <= 0:
                continue
            if above and price < mid <= price + max_dist:
                levels.append(mid)
            if not above and price - max_dist <= mid < price:
                levels.append(mid)
        for vp in [poc, vah, val]:
            if vp <= 0:
                continue
            if above and price < vp <= price + max_dist:
                levels.append(vp)
            if not above and price - max_dist <= vp < price:
                levels.append(vp)
        return list(set(levels))

    atr_buffer = max(atr * 1.5, price * sl_ratio * 0.5)

    if action == "SELL":
        tp_candidates = sorted(build_candidates(False), reverse=True)
        sl_cands = []
        for p in resistances:
            if price < p <= price + max_dist:
                sl_cands.append(p)
        for ob in order_blocks:
            if (ob.get("type") or "").lower() == "bearish":
                top = float(ob.get("top") or 0)
                if price < top <= price + max_dist:
                    sl_cands.append(top)
        sl_cands.sort()
        sl = (min(sl_cands[0] + atr_buffer, price + max_dist)
              if sl_cands else price + price * sl_ratio)
    else:  # BUY
        tp_candidates = sorted(build_candidates(True))
        sl_cands = []
        for p in supports:
            if price - max_dist <= p < price:
                sl_cands.append(p)
        for ob in order_blocks:
            if (ob.get("type") or "").lower() == "bullish":
                bottom = float(ob.get("bottom") or 0)
                if price - max_dist <= bottom < price:
                    sl_cands.append(bottom)
        sl_cands.sort(reverse=True)
        sl = (max(sl_cands[0] - atr_buffer, price - max_dist)
              if sl_cands else price - price * sl_ratio)

    # Pick TP1/TP2/TP3 spaced at least min_step apart
    tp1 = tp2 = tp3 = None
    picked = []
    for c in tp_candidates:
        if abs(c - price) < min_step:
            continue
        if any(abs(c - prev) < min_step for prev in picked):
            continue
        picked.append(c)
        if tp1 is None:
            tp1 = c
        elif tp2 is None:
            tp2 = c
        elif tp3 is None:
            tp3 = c
            break

    # Synthesise missing levels
    if tp1 is None:
        tp1 = (price - min_step * 2) if action == "SELL" else (price + min_step * 2)
    if tp2 is None:
        tp2 = (tp1 - min_step * 2)   if action == "SELL" else (tp1 + min_step * 2)
    if tp3 is None:
        tp3 = (tp2 - min_step * 2)   if action == "SELL" else (tp2 + min_step * 2)

    # Clamp to max distance & enforce ordering
    floor = price - max_dist
    ceiling = price + max_dist
    if action == "SELL":
        tp1 = max(tp1, floor); tp2 = max(tp2, floor); tp3 = max(tp3, floor)
        if tp2 >= tp1: tp2 = tp1 - min_step
        if tp3 >= tp2: tp3 = tp2 - min_step
    else:
        tp1 = min(tp1, ceiling); tp2 = min(tp2, ceiling); tp3 = min(tp3, ceiling)
        if tp2 <= tp1: tp2 = tp1 + min_step
        if tp3 <= tp2: tp3 = tp2 + min_step

    risk   = abs(price - sl)
    reward = abs(tp2 - price)
    rr     = round(reward / risk, 2) if risk > 0 else 0

    if risk <= 0 or rr <= 0:
        return None

    return {
        "tp1": round(tp1, 8),
        "tp2": round(tp2, 8),
        "tp3": round(tp3, 8),
        "sl":  round(sl,  8),
        "rr":  rr,
    }


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

        bias, confidence, _ = compute_overall_bias(classical, smc, harmonic, volume)
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
    Walk-forward replay that simulates an actual trade using the SAME
    compute_deterministic_levels() logic the live scanner uses for TP/SL —
    sourced from real chart structure (S/R, Order Blocks, FVGs, Volume Profile)
    instead of a fixed % grid. Falls back to the fixed grid only when no
    usable chart levels exist (e.g. brand-new coin with no history).
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

        bias, confidence, _ = compute_overall_bias(classical, smc, harmonic, volume)
        if bias not in ("bullish", "bearish"):
            continue

        entry = float(window["close"].iloc[-1])
        action = "BUY" if bias == "bullish" else "SELL"
        is_buy = action == "BUY"

        # Use deterministic levels from real chart structure (same as live scanner)
        levels = compute_deterministic_levels(action, entry, classical, smc, volume, constraints)
        if levels:
            tp1, tp2, tp3, sl = levels["tp1"], levels["tp2"], levels["tp3"], levels["sl"]
        else:
            # Fallback: fixed grid (only when no chart levels found)
            atr = float(classical.get("atr", {}).get("current") or 0)
            sl_dist = entry * constraints["sl_ratio"]
            if atr > 0:
                sl_dist = max(sl_dist, atr * 1.5)
            tp_step_dist = entry * constraints["tp_step"]
            sl  = entry - sl_dist      if is_buy else entry + sl_dist
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


def _build_chart_overlays(classical: dict, smc: dict, harmonic: dict, volume: dict) -> dict:
    """Build structured chart overlay data for the frontend."""
    overlays = {
        "support_resistance": [],
        "moving_averages": [],
        "order_blocks": [],
        "fair_value_gaps": [],
        "bos_choch": [],
        "liquidity_zones": [],
        "fibonacci": [],
        "harmonic_patterns": [],
        "chart_patterns": [],
        "volume_profile": {}
    }

    # Support & Resistance
    sr = classical.get("support_resistance", {})
    for s in sr.get("support", []):
        overlays["support_resistance"].append({
            "type": "horizontal_line",
            "price": s["price"],
            "color": "#00E676",
            "label": f"S ({s['strength']}x)",
            "line_style": "dashed"
        })
    for r in sr.get("resistance", []):
        overlays["support_resistance"].append({
            "type": "horizontal_line",
            "price": r["price"],
            "color": "#FF1744",
            "label": f"R ({r['strength']}x)",
            "line_style": "dashed"
        })

    # Moving Averages
    mas = classical.get("moving_averages", {})
    ma_colors = {
        "ema_20": "#00D4FF",
        "ema_50": "#FFC107",
        "ema_100": "#FF9800",
        "ema_200": "#FF1744"
    }
    for ma_key, color in ma_colors.items():
        if mas.get(ma_key):
            overlays["moving_averages"].append({
                "type": "horizontal_line",
                "price": mas[ma_key],
                "color": color,
                "label": ma_key.upper(),
                "line_style": "solid"
            })

    # Order Blocks
    for ob in smc.get("order_blocks", []):
        overlays["order_blocks"].append({
            "type": "rectangle",
            "top": ob["top"],
            "bottom": ob["bottom"],
            "color": "#00E67633" if ob["type"] == "bullish" else "#FF174433",
            "border_color": "#00E676" if ob["type"] == "bullish" else "#FF1744",
            "label": f"OB ({ob['type']})",
            "timestamp": ob.get("timestamp")
        })

    # Fair Value Gaps
    for fvg in smc.get("fair_value_gaps", []):
        is_bull = fvg["type"] == "bullish"
        overlays["fair_value_gaps"].append({
            "type": "rectangle",
            "top": fvg["top"],
            "bottom": fvg["bottom"],
            "color": "#00E67615" if is_bull else "#FF174415",
            "border_color": "#00E676" if is_bull else "#FF1744",
            "label": f"FVG ({fvg['type']})",
            "timestamp": fvg.get("timestamp")
        })

    # BOS / CHoCH
    structure = smc.get("market_structure", {})
    for bos in structure.get("bos", []):
        overlays["bos_choch"].append({
            "type": "horizontal_line",
            "price": bos["price"],
            "color": "#6C63FF",
            "label": f"BOS ({bos['direction']})",
            "line_style": "solid"
        })
    for choch in structure.get("choch", []):
        overlays["bos_choch"].append({
            "type": "horizontal_line",
            "price": choch["price"],
            "color": "#00D4FF",
            "label": f"CHoCH ({choch['direction']})",
            "line_style": "dashed"
        })

    # Liquidity Zones
    liq = smc.get("liquidity_zones", {})
    for lz in liq.get("buy_side", []):
        overlays["liquidity_zones"].append({
            "type": "horizontal_line",
            "price": lz["price"],
            "color": "#00E676aa",
            "label": "BSL",
            "line_style": "dotted"
        })
    for lz in liq.get("sell_side", []):
        overlays["liquidity_zones"].append({
            "type": "horizontal_line",
            "price": lz["price"],
            "color": "#FF1744aa",
            "label": "SSL",
            "line_style": "dotted"
        })

    # Fibonacci Levels
    fib = harmonic.get("fibonacci", {})
    if fib.get("levels"):
        key_fibs = ["0.236", "0.382", "0.5", "0.618", "0.786"]
        fib_colors = {
            "0.236": "#FFD700",
            "0.382": "#FFA500",
            "0.5": "#FF6347",
            "0.618": "#FF4500",
            "0.786": "#DC143C"
        }
        for level_key, color in fib_colors.items():
            if level_key in fib["levels"]:
                overlays["fibonacci"].append({
                    "type": "horizontal_line",
                    "price": fib["levels"][level_key],
                    "color": color,
                    "label": f"Fib {level_key}",
                    "line_style": "dashed"
                })

    # Harmonic Patterns
    for pattern in harmonic.get("patterns", []):
        overlays["harmonic_patterns"].append({
            "type": "pattern",
            "name": pattern["pattern"],
            "direction": pattern["direction"],
            "completion": pattern["completion"],
            "target1": pattern.get("target1"),
            "target2": pattern.get("target2"),
            "stop_loss": pattern.get("stop_loss"),
            "confidence": pattern.get("confidence")
        })

    # Classical Chart Patterns
    for p in classical.get("chart_patterns", []):
        direction = p.get("direction")
        color = "#00E676" if direction == "bullish" else ("#FF1744" if direction == "bearish" else "#FFC107")
        entry = {
            "name": p.get("name"),
            "direction": direction,
            "confidence": p.get("confidence", p.get("strength")),
            "color": color,
        }
        if "resistance_points" in p and "support_points" in p:
            entry["kind"] = "trendlines"
            entry["resistance_points"] = p["resistance_points"]
            entry["support_points"] = p["support_points"]
        else:
            entry["kind"] = "levels"
            entry["levels"] = [
                {"label": k.replace("_", " ").title(), "price": v}
                for k, v in (p.get("levels") or {}).items()
                if isinstance(v, (int, float))
            ]
        overlays["chart_patterns"].append(entry)

    # Volume Profile
    vp = volume.get("volume_profile", {})
    if vp:
        overlays["volume_profile"] = {
            "poc": vp.get("poc"),
            "vah": vp.get("vah"),
            "val": vp.get("val"),
            "profile": vp.get("profile", [])[:30]
        }

    return overlays


def run_chart_backtest(
    symbol: str,
    exchange: str = "binance",
    interval: str = "15m",
    limit: int = 500,
    warmup: int = 200,
    step: int = 1,
    min_confidence: int = 0,
) -> dict:
    """
    Same walk-forward simulation as run_realistic_backtest(), but returns the
    full candle series plus EVERY analyzed point so a frontend can plot predictions
    directly on the historical chart.  Uses compute_deterministic_levels() for
    TP/SL — same as the live scanner — instead of a fixed % grid.
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

    for i in range(warmup, n - expiry_candles, max(1, step)):
        window = df.iloc[: i + 1]
        try:
            classical = run_classical_analysis(window)
            smc = run_smc_analysis(window)
            harmonic = run_harmonic_analysis(window)
            volume = run_volume_profile_analysis(window)
        except Exception:
            continue

        bias, confidence, _ = compute_overall_bias(classical, smc, harmonic, volume)
        if bias not in ("bullish", "bearish") or confidence < min_confidence:
            continue

        entry = float(window["close"].iloc[-1])
        entry_time = int(window["open_time"].iloc[-1].timestamp())
        action = "BUY" if bias == "bullish" else "SELL"
        is_buy = action == "BUY"

        # Capture structured chart overlays at this prediction point
        overlays = _build_chart_overlays(classical, smc, harmonic, volume)

        # Use deterministic levels from real chart structure (same as live scanner)
        levels = compute_deterministic_levels(action, entry, classical, smc, volume, constraints)
        if levels:
            tp1, tp2, tp3, sl = levels["tp1"], levels["tp2"], levels["tp3"], levels["sl"]
        else:
            # Fallback: fixed grid (only when no chart levels found)
            atr = float(classical.get("atr", {}).get("current") or 0)
            sl_dist = entry * constraints["sl_ratio"]
            if atr > 0:
                sl_dist = max(sl_dist, atr * 1.5)
            tp_step_dist = entry * constraints["tp_step"]
            sl  = entry - sl_dist      if is_buy else entry + sl_dist
            tp1 = entry + tp_step_dist if is_buy else entry - tp_step_dist
            tp2 = entry + tp_step_dist * 2 if is_buy else entry - tp_step_dist * 2
            tp3 = entry + tp_step_dist * 3 if is_buy else entry - tp_step_dist * 3

        outcome = "expired"
        outcome_time = None
        mfe_val = 0.0
        mae_val = 0.0
        would_hit_tp = False

        for j in range(i + 1, min(i + 1 + expiry_candles, n)):
            high = float(df["high"].iloc[j])
            low = float(df["low"].iloc[j])
            candle_time = int(df["open_time"].iloc[j].timestamp())

            if outcome == "hit_sl":
                if is_buy and high >= tp1:
                    would_hit_tp = True
                    break
                if not is_buy and low <= tp1:
                    would_hit_tp = True
                    break
                continue

            if is_buy:
                candle_mfe = ((high - entry) / entry) * 100
                candle_mae = ((low - entry) / entry) * 100
            else:
                candle_mfe = ((entry - low) / entry) * 100
                candle_mae = ((entry - high) / entry) * 100

            if candle_mfe > mfe_val: mfe_val = candle_mfe
            if candle_mae < mae_val: mae_val = candle_mae

            hit_sl = (low <= sl) if is_buy else (high >= sl)
            if hit_sl:
                outcome, outcome_time = "hit_sl", candle_time
                continue

            if is_buy:
                if high >= tp3: outcome, outcome_time = "hit_tp3", candle_time; break
                if high >= tp2: outcome, outcome_time = "hit_tp2", candle_time
                elif high >= tp1 and outcome == "expired": outcome, outcome_time = "hit_tp1", candle_time
            else:
                if low <= tp3: outcome, outcome_time = "hit_tp3", candle_time; break
                if low <= tp2: outcome, outcome_time = "hit_tp2", candle_time
                elif low <= tp1 and outcome == "expired": outcome, outcome_time = "hit_tp1", candle_time

        records.append({
            "time": entry_time,
            "bias": bias,
            "confidence": confidence,
            "entry": round(entry, 8),
            "tp1": round(tp1, 8), "tp2": round(tp2, 8), "tp3": round(tp3, 8), "sl": round(sl, 8),
            "outcome": outcome,
            "outcome_time": outcome_time,
            "correct": outcome.startswith("hit_tp"),
            "mfe": round(mfe_val, 2),
            "mae": round(mae_val, 2),
            "would_hit_tp": would_hit_tp,
            "overlays": overlays,
        })

    candles = [
        {
            "time": int(row["open_time"].timestamp()),
            "open": float(row["open"]), "high": float(row["high"]),
            "low": float(row["low"]), "close": float(row["close"]),
        }
        for _, row in df.iterrows()
    ]

    summary = _summarize_realistic(records, symbol, interval)
    return {"symbol": symbol, "interval": interval, "candles": candles, "predictions": records, "summary": summary}


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
