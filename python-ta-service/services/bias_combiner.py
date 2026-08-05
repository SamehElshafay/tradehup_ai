"""
Combines the four analysis schools (Classical, SMC, Harmonic, Volume Profile)
into a single overall_bias + overall_confidence.

Extracted out of main.py's /analyze handler so the exact same weighting logic
can be replayed point-in-time by the backtester — the whole point of the
backtest is measuring THIS function's real-world accuracy, so it must be the
same code path production uses, not a re-implementation that can drift.

Weights below (30/10/50/10) replace the original hand-picked 30/40/20/10 split.
A grid search (services/weight_search.py) over 1470 real historical points
across 5 symbols showed SMC's standalone real win rate (16.7%) was the second
worst of the four schools, yet it held the highest weight (40%) — SMC's raw
bias call was ~70% bullish regardless of symbol or actual market direction,
so it dragged the blended score down. Harmonic alone scored highest (20.7%).
SMC's weight is kept small rather than zero because it still carries some
signal in the classical_trend override below. This does NOT affect SMC's
order blocks / FVGs / market structure data, which are still fully computed
and used elsewhere (AI prompt, PreTradeFilterService, chart overlays) — only
its vote in this single combined score is down-weighted.
"""


def compute_overall_bias(classical: dict, smc: dict, harmonic: dict, volume: dict) -> tuple:
    """Returns (overall_bias: str, overall_confidence: int)."""
    bias_scores = {"bullish": 0, "bearish": 0}

    # Classical weight: 30%
    if classical["bias"] == "bullish":
        bias_scores["bullish"] += 30 * (classical["bullish_signals"] / max(classical["bullish_signals"] + classical["bearish_signals"], 1))
    elif classical["bias"] == "bearish":
        bias_scores["bearish"] += 30 * (classical["bearish_signals"] / max(classical["bullish_signals"] + classical["bearish_signals"], 1))

    # SMC weight: 10% (down from 40% — see module docstring)
    if smc["bias"] == "bullish":
        bias_scores["bullish"] += 10
    elif smc["bias"] == "bearish":
        bias_scores["bearish"] += 10

    # Harmonic weight: 50% (up from 20% — best standalone real accuracy)
    if harmonic["bias"] == "bullish":
        bias_scores["bullish"] += 50
    elif harmonic["bias"] == "bearish":
        bias_scores["bearish"] += 50

    # Volume Profile weight: 10%
    if volume["bias"] in ["bullish", "slightly_bullish"]:
        bias_scores["bullish"] += 10
    elif volume["bias"] in ["bearish", "slightly_bearish"]:
        bias_scores["bearish"] += 10

    classical_trend = classical.get("moving_averages", {}).get("trend", "neutral")
    overall_bias = "bullish" if bias_scores["bullish"] > bias_scores["bearish"] else "bearish"
    # Hard override: if price is below ALL 3 EMAs AND SMC is also bearish → force bearish
    if classical_trend in ["strong_downtrend"] and smc.get("bias") != "bullish":
        overall_bias = "bearish"
    overall_confidence = int(max(bias_scores["bullish"], bias_scores["bearish"]))

    return overall_bias, overall_confidence
