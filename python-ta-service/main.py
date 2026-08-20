from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from typing import Optional
import traceback
import pandas as pd

from services.binance_fetcher import fetch_ohlcv, fetch_ticker_price, fetch_top_coins, _cache
from services.classical_analysis import run_classical_analysis
from services.smc_analysis import run_smc_analysis
from services.harmonic_analysis import run_harmonic_analysis
from services.volume_profile import run_volume_profile_analysis, get_volume_decision_context
from services.bias_combiner import compute_overall_bias
from services.liquidity_sweep import detect_liquidity_sweeps
from services.market_regime import detect_market_regime
from services.backtest import run_backtest, run_realistic_backtest, run_chart_backtest
from services.weight_search import search_weights
from services.trade_optimizer import optimize_trade_params
from services.filter_validator import validate_filter
from services.ob_rule_validator import validate_ob_rule
from services.ml_service import ml_service
from services.historical_trainer import generate_historical_dataset
from services.order_book_analysis import fetch_order_book, analyze_order_book
from services.cvd_analysis import calculate_cvd
from services.taker_pressure_analysis import analyze_taker_pressure

# Clear any stale cache on startup
_cache.clear()

app = FastAPI(
    title="AI Trading — TA Engine",
    description="Technical Analysis Microservice for the AI Trading Platform",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class AnalysisRequest(BaseModel):
    symbol: str = Field(..., description="Trading pair symbol (e.g., BTCUSDT)")
    exchange: str = Field("binance", description="Exchange name")
    interval: str = Field("4h", description="Timeframe (e.g., 15m, 1h, 4h, 1d)")
    limit: int = Field(500, description="Number of historical candles to fetch")
    include_harmonic: bool = Field(True, description="Whether to include harmonic pattern detection")
    include_classical: bool = Field(True, description="Whether to include classical chart pattern detection")
    include_candles: bool = Field(True, description="Whether to include candlestick pattern detection")
    include_order_book: bool = Field(False, description="Fetch live order book for spread/depth/imbalance analysis")
    include_cvd: bool = Field(False, description="Compute CVD (Cumulative Volume Delta) from OHLCV taker data")
    include_taker_pressure: bool = Field(False, description="Analyse taker buy/sell pressure as OI alternative for Spot")


@app.get("/health")
def health_check():
    return {"status": "ok", "service": "ta_engine"}

from typing import List, Dict, Any

@app.post("/ml/train")
def train_model(dataset: List[Dict[str, Any]]):
    try:
        result = ml_service.train(dataset)
        return result
    except Exception as e:
        traceback.print_exc()
        raise HTTPException(status_code=400, detail=str(e))

@app.post("/ml/predict")
def predict_model(opportunity: Dict[str, Any]):
    try:
        result = ml_service.predict(opportunity)
        return result
    except Exception as e:
        traceback.print_exc()
        raise HTTPException(status_code=400, detail=str(e))


class HistoricalTrainRequest(BaseModel):
    coins: Optional[List[str]] = None
    timeframes: Optional[List[str]] = None
    limit: int = 1000


@app.post("/ml/train-historical")
def train_on_historical(req: HistoricalTrainRequest):
    """
    Fetch real OHLCV data from Binance for the specified coins/timeframes,
    generate thousands of labeled synthetic trade samples, then train the ML model.
    """
    try:
        dataset = generate_historical_dataset(
            coins=req.coins,
            timeframes=req.timeframes,
            limit=req.limit,
        )
        if not dataset:
            raise HTTPException(status_code=400, detail="No training samples were generated. Check coins/timeframes.")

        result = ml_service.train(dataset)
        return result
    except HTTPException:
        raise
    except Exception as e:
        traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/ml/train-historical/status")
def train_historical_status():
    """Returns current model status."""
    return {
        "model_loaded": ml_service.model is not None,
        "status": "ready" if ml_service.model is not None else "not_trained",
    }

@app.post("/analyze")
def analyze(req: AnalysisRequest):
    """
    Main endpoint: runs all TA analysis (Classical + SMC + Harmonic + Volume Profile)
    and returns comprehensive JSON with chart overlay data.
    """
    symbol = req.symbol.upper()
    valid_intervals = ["1m", "5m", "15m", "30m", "1h", "2h", "4h", "6h", "12h", "1d", "3d", "1w", "1M"]
    if req.interval not in valid_intervals:
        raise HTTPException(status_code=400, detail=f"Invalid interval. Valid: {valid_intervals}")

    def fmt(price) -> str:
        """Format price adaptively based on its magnitude."""
        if price is None:
            return "N/A"
        price = float(price)
        if price == 0:
            return "0"
        if price >= 1000:
            return f"{price:,.2f}"
        if price >= 1:
            return f"{price:.4f}"
        if price >= 0.01:
            return f"{price:.6f}"
        # Very small prices: show 8 significant figures
        from decimal import Decimal
        d = Decimal(str(price))
        return format(d, 'f').rstrip('0').rstrip('.')

    try:
        # Fetch OHLCV data
        df = fetch_ohlcv(symbol, req.exchange, req.interval, req.limit)

        if df is None or len(df) < 50:
            raise HTTPException(status_code=400, detail="Not enough data for analysis")

        # Live price for the "current_price" field — fetch_ohlcv now drops the
        # still-forming candle (see binance_fetcher.py) so the last row's close
        # is up to one full candle stale. Entry/SL/TP need the actual live
        # price, not a several-minutes-old closed-candle close. Falls back to
        # the last closed close if the ticker call itself fails.
        try:
            live_price = fetch_ticker_price(symbol, req.exchange)
        except Exception:
            live_price = float(df["close"].iloc[-1])

        # Run all analysis in sequence
        classical = run_classical_analysis(
            df,
            include_patterns=req.include_classical,
            include_candles=req.include_candles
        )
        smc = run_smc_analysis(df)
        # Harmonic (XA-BC-CD wave pattern detection) is the most compute-heavy school and
        # user-toggleable in Settings — skip the scan entirely rather than compute-then-discard.
        harmonic = run_harmonic_analysis(df) if req.include_harmonic else {
            "patterns": [], "fibonacci": {}, "bias": "neutral", "patterns_count": 0,
        }
        volume = run_volume_profile_analysis(df)

        # ── Phase 2: Market Regime + Liquidity Sweep + Volume Context ──────────
        # Regime is now computed BEFORE bias so the modifier can be applied
        # inside compute_overall_bias (Phase 3 fix).
        market_regime      = detect_market_regime(df, classical, smc, req.interval)
        liquidity_sweeps   = detect_liquidity_sweeps(df, smc)
        volume_context     = get_volume_decision_context(
            volume, None, float(df["close"].iloc[-1])
        )

        # Determine overall bias by weighting all schools.
        # Phase 3: pass market_regime and patterns so regime modifier + conflict
        # penalty are applied inside compute_overall_bias instead of being advisory-only.
        overall_bias, overall_confidence, score_breakdown = compute_overall_bias(
            classical, smc, harmonic, volume,
            market_regime     = market_regime,
            classical_patterns= classical.get("chart_patterns") or [],
            harmonic_patterns = harmonic.get("patterns") or [],
        )

        # Re-run volume context now that we have overall_bias
        volume_context = get_volume_decision_context(
            volume, overall_bias, float(df["close"].iloc[-1])
        )

        # Collect confluences — each must be directionally validated vs. current price
        confluences = []
        last_close = float(df["close"].iloc[-1])

        if smc.get("market_structure", {}).get("bos"):
            for b in smc["market_structure"]["bos"]:
                confluences.append(f"BOS {b['direction']} at {fmt(b['price'])}")
        if smc.get("market_structure", {}).get("choch"):
            for c in smc["market_structure"]["choch"]:
                confluences.append(f"CHoCH {c['direction']} — potential reversal")
        if classical.get("rsi", {}).get("condition") in ["overbought", "oversold"]:
            confluences.append(f"RSI {classical['rsi']['condition']} at {classical['rsi']['current']:.1f}")
        if classical.get("chart_patterns"):
            PATTERN_STATUS_LABELS = {
                "forming": "forming — trigger level not yet broken",
                "confirmed_breakdown": "CONFIRMED breakdown (neckline broken + volume confirmed)",
                "confirmed_breakout": "CONFIRMED breakout (trigger broken + volume confirmed)",
                "breakdown_pending_confirmation": "breakdown WITHOUT volume confirmation yet — weak signal",
                "breakout_pending_confirmation": "breakout WITHOUT volume confirmation yet — weak signal",
            }
            for p in classical["chart_patterns"]:
                status = p.get("status", "unknown")
                if status == "invalidated":
                    # Already broken against its own thesis by current price — not an
                    # active directional signal, don't present it as a confluence.
                    continue
                status_label = PATTERN_STATUS_LABELS.get(status, status)
                confluences.append(f"{p['name']} ({p['direction']}) — {status_label}")
        if harmonic.get("patterns"):
            for p in harmonic["patterns"]:
                confluences.append(f"{p['pattern']} pattern ({p['direction']}) detected")

        # POC confluence — describe its position relative to price correctly
        if volume.get("key_levels", {}).get("poc"):
            poc = volume["key_levels"]["poc"]
            vah = volume["key_levels"].get("vah")
            val = volume["key_levels"].get("val")

            if vah and val and val <= last_close <= vah:
                pos_desc = "inside value area"
            elif vah and last_close > vah:
                pos_desc = "above value area"
            elif val and last_close < val:
                pos_desc = "below value area"
            else:
                pos_desc = "near value area"

            if last_close > poc:
                poc_desc = f"POC at {fmt(poc)} (below current price — price trading {pos_desc})"
            else:
                poc_desc = f"POC at {fmt(poc)} (above current price — price trading {pos_desc})"
            confluences.append(poc_desc)

        bb = classical.get("bollinger_bands", {})
        if bb.get("position"):
            if bb["position"] == "below_lower":
                confluences.append(f"Price is below Bollinger lower band at {fmt(bb['lower'])} (oversold/reversal potential)")
            elif bb["position"] == "above_upper":
                confluences.append(f"Price is above Bollinger upper band at {fmt(bb['upper'])} (overbought/reversal potential)")
            else:
                if bb.get("lower") and (last_close - bb["lower"]) / last_close < 0.005:
                    confluences.append(f"Price is near Bollinger lower band at {fmt(bb['lower'])} (potential support)")
                elif bb.get("upper") and (bb["upper"] - last_close) / last_close < 0.005:
                    confluences.append(f"Price is near Bollinger upper band at {fmt(bb['upper'])} (potential resistance)")


        if classical.get("moving_averages", {}).get("trend"):
            trend_str = classical["moving_averages"]["trend"]
            pve = classical["moving_averages"].get("price_vs_emas", {})
            b_count = pve.get("below_count", 0)
            a_count = pve.get("above_count", 0)
            ema20_pct = pve.get("ema20_pct")
            cross = classical["moving_averages"].get("cross_signal")
            
            cross_str = f" | {cross} (EMA50/200) detected!" if cross else ""
            
            if b_count == 3:
                trend_desc = f"MA Trend: strong_downtrend (price below ALL 3 EMAs by EMA20:{ema20_pct:.2f}%){cross_str}"
            elif a_count == 3:
                trend_desc = f"MA Trend: strong_uptrend (price above ALL 3 EMAs){cross_str}"
            else:
                trend_desc = f"MA Trend: {trend_str} (above {a_count}/3 EMAs, below {b_count}/3 EMAs){cross_str}"
            confluences.append(trend_desc)

        # ── FVG Analysis: above + below price ──────────────────────────────────
        # First, detect BLOCKING Bearish FVGs (between current price and first TP target)
        # These are critical because they physically block upward price movement
        overhead_bearish_fvgs = []
        nearby_fvgs = []
        for fvg in smc.get("fair_value_gaps", []):
            fvg_mid  = (fvg["top"] + fvg["bottom"]) / 2
            dist_pct = (fvg_mid - last_close) / last_close * 100  # positive = above price
            if 0 < dist_pct <= 5.0 and fvg["type"] == "bearish":
                overhead_bearish_fvgs.append(
                    f"[{req.interval}] ⚠️ BLOCKING BEARISH FVG at {fmt(fvg['bottom'])}–{fmt(fvg['top'])} "
                    f"({dist_pct:.2f}% above price) — blocks upward move to POC/resistance"
                )
            elif abs(dist_pct) <= 5.0:
                position = "below" if fvg_mid < last_close else "above"
                nearby_fvgs.append(
                    f"[{req.interval}] {fvg['type'].upper()} FVG {fmt(fvg['bottom'])}–{fmt(fvg['top'])} ({position} price, {abs(dist_pct):.1f}%)"
                )

        # Put blocking FVGs first — they are the most critical
        confluences.extend(overhead_bearish_fvgs)
        confluences.extend(nearby_fvgs[:2])

        # Build chart overlays for frontend
        chart_overlays = _build_chart_overlays(classical, smc, harmonic, volume)

        # OHLCV candles for chart (last 200)
        candles = df.tail(200).apply(
            lambda row: {
                "time": int(row["open_time"].timestamp()),
                "open": float(row["open"]),
                "high": float(row["high"]),
                "low": float(row["low"]),
                "close": float(row["close"]),
                "volume": float(row["volume"])
            }, axis=1
        ).tolist()

        # Sum the actual volume of every candle whose open_time falls within the last
        # 24h — works for any interval (1m..1w) instead of a hardcoded per-interval
        # candle count, which previously fell back to a single candle's volume for
        # any timeframe other than exactly '1h' or '4h' (e.g. 15m, 5m, 30m).
        volume_col = pd.to_numeric(df.get("quote_volume", df["volume"]))
        last_time = df["open_time"].iloc[-1]
        window_mask = df["open_time"] >= (last_time - pd.Timedelta(hours=24))
        volume_24h = float(volume_col[window_mask].sum())

        # Calculate Velocity (speed of price movement over last 5 candles)
        try:
            close_series = pd.to_numeric(df["close"])
            velocity = float((close_series.iloc[-1] - close_series.shift(5).iloc[-1]) / 5)
        except Exception:
            velocity = 0.0

        # ── Phase 4: Optional Execution-Quality Engines ────────────────────────
        # Each engine is gated by its flag — when disabled, returns None so the
        # backend and report generator can skip it entirely.
        order_book_result = None
        if req.include_order_book:
            try:
                raw_ob = fetch_order_book(symbol, req.exchange)
                order_book_result = analyze_order_book(raw_ob, live_price)
            except Exception:
                order_book_result = None

        cvd_result = None
        if req.include_cvd:
            try:
                cvd_result = calculate_cvd(df)
            except Exception:
                cvd_result = None

        taker_pressure_result = None
        if req.include_taker_pressure:
            try:
                taker_pressure_result = analyze_taker_pressure(df, overall_bias)
            except Exception:
                taker_pressure_result = None

        return {
            "symbol": symbol,
            "interval": req.interval,
            "analyzed_at": str(df["open_time"].iloc[-1]),
            "current_price": live_price,
            "volume_24h": volume_24h,
            "velocity": velocity,
            "overall_bias": overall_bias,
            "overall_confidence": overall_confidence,
            "score_breakdown": score_breakdown,
            "confluences": confluences,
            "chart_overlays": chart_overlays,
            "candles": candles,
            "classical": {
                **classical,
                "confidence": classical.get("confidence", 0)
            },
            "smc": {
                **smc,
                "confidence": smc.get("confidence", 0)
            },
            "harmonic"               : harmonic,
            "volume_profile"         : volume,
            "liquidity_sweeps"       : liquidity_sweeps,
            "market_regime"          : market_regime,
            "volume_decision_context": volume_context,
            "order_book"             : order_book_result,
            "cvd_analysis"           : cvd_result,
            "taker_pressure"         : taker_pressure_result,
        }

    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Analysis failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/ohlcv")
def get_ohlcv(symbol: str, exchange: str = "binance", interval: str = "4h", limit: int = 200):
    """Get raw OHLCV data from Exchange."""
    try:
        df = fetch_ohlcv(symbol.upper(), exchange, interval, limit)
        candles = df.apply(
            lambda row: {
                "time": int(row["open_time"].timestamp()),
                "open": float(row["open"]),
                "high": float(row["high"]),
                "low": float(row["low"]),
                "close": float(row["close"]),
                "volume": float(row["volume"])
            }, axis=1
        ).tolist()
        return {"symbol": symbol.upper(), "interval": interval, "candles": candles}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/price/{symbol}")
def get_price(symbol: str, exchange: str = "binance"):
    """Get current price for a symbol."""
    try:
        price = fetch_ticker_price(symbol.upper(), exchange)
        return {"symbol": symbol.upper(), "price": price}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/top-coins")
def get_top_coins(limit: int = 50):
    """Get top USDT pairs by 24h volume from Binance."""
    try:
        coins = fetch_top_coins(limit)
        return {"coins": coins, "count": len(coins)}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/backtest")
def backtest(
    symbol: str,
    exchange: str = "binance",
    interval: str = "15m",
    limit: int = 1000,
    horizon: int = 6,
    min_move_pct: float = 0.3,
):
    """
    Walk-forward validation of the deterministic Classical+SMC+Harmonic+Volume
    engine, independent of the AI. Replays historical candles, re-runs the
    same analysis at each point using only data available then, and checks
    whether price actually moved in the predicted direction `horizon`
    candles later. Returns a real win-rate-by-confidence-bucket table.
    """
    try:
        return run_backtest(
            symbol=symbol.upper(),
            exchange=exchange,
            interval=interval,
            limit=limit,
            horizon=horizon,
            min_move_pct=min_move_pct,
        )
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Backtest failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/backtest-realistic")
def backtest_realistic(symbol: str, exchange: str = "binance", interval: str = "15m", limit: int = 1000):
    """
    Like /backtest, but simulates the ACTUAL trade rules (TP1/TP2/TP3/SL from
    the same timeframe constraints + ATR gate AnalysisController uses, walked
    candle-by-candle with SL checked first) instead of a simple % move
    threshold. Directly comparable to real paper-trade win rates.
    """
    try:
        return run_realistic_backtest(symbol=symbol.upper(), exchange=exchange, interval=interval, limit=limit)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Realistic backtest failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/optimize-trade-params")
def optimize_trade_params_endpoint(symbols: str, interval: str = "15m", limit: int = 500):
    """
    Grid-searches TP-distance / expiry-window / SL-distance multipliers for
    the combo that maximizes real expectancy (win_rate*avg_win - loss_rate*
    avg_loss, scaled by how often trades actually resolve) — not raw win
    rate, which can be trivially gamed with a tiny TP and a huge SL.
    `symbols` is comma-separated, e.g. "BTCUSDT,ETHUSDT,PENGUUSDT".
    """
    try:
        symbol_list = [s.strip().upper() for s in symbols.split(",") if s.strip()]
        return optimize_trade_params(symbols=symbol_list, interval=interval, limit=limit)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Trade param search failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/validate-filter")
def validate_filter_endpoint(symbols: str, interval: str = "15m", limit: int = 500):
    """
    Replays PreTradeFilterService's Market Structure check + Pattern Agreement
    penalty + Confidence Gate against real historical outcomes (using the
    tuned tp/expiry/sl multipliers from /optimize-trade-params), comparing a
    no-filter baseline against several candidate confidence thresholds — so
    the 65% default can be checked against data instead of staying a guess.
    """
    try:
        symbol_list = [s.strip().upper() for s in symbols.split(",") if s.strip()]
        return validate_filter(symbols=symbol_list, interval=interval, limit=limit)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Filter validation failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/backtest-chart")
def backtest_chart(symbol: str, exchange: str = "binance", interval: str = "15m", limit: int = 500, step: int = 1, min_confidence: int = 0):
    """
    Full walk-forward backtest returning EVERY analyzed point (not a 50-record
    sample) plus the raw candle series, so a frontend can plot predictions
    directly on the historical chart against what price actually did.
    """
    try:
        return run_chart_backtest(symbol=symbol.upper(), exchange=exchange, interval=interval, limit=limit, step=step, min_confidence=min_confidence)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Chart backtest failed: {str(e)}\n{traceback.format_exc()}")


@app.get("/optimize-weights")
def optimize_weights(
    symbols: str,
    interval: str = "15m",
    limit: int = 500,
    horizon: int = 6,
    min_move_pct: float = 0.3,
    step: int = 10,
):
    """
    Grid-searches Classical/SMC/Harmonic/Volume weight combinations (in steps
    of `step`, summing to 100) against real historical outcomes, to find
    weights that actually beat the current hand-picked 30/40/20/10 split.
    `symbols` is a comma-separated list, e.g. "BTCUSDT,ETHUSDT,SOLUSDT".
    """
    try:
        symbol_list = [s.strip().upper() for s in symbols.split(",") if s.strip()]
        return search_weights(
            symbols=symbol_list,
            interval=interval,
            limit=limit,
            horizon=horizon,
            min_move_pct=min_move_pct,
            step=step,
        )
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Weight search failed: {str(e)}\n{traceback.format_exc()}")


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

    # Classical Chart Patterns (Double/Triple Top/Bottom, Head & Shoulders, Triangles/Wedges)
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
            "profile": vp.get("profile", [])[:30]  # Limit for chart performance
        }

    return overlays
