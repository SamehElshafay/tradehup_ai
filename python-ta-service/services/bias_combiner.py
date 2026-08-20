"""
Combines the four analysis schools (Classical, SMC, Harmonic, Volume Profile)
into a single overall_bias + overall_confidence.

Phase 3 additions (2026-08):
─────────────────────────────────────────────────────────────────────
1. SMC is now ratio-weighted (like Classical) — OB+FVG+structure signal
   strength proportional, not a binary full-or-nothing contribution.
2. Regime modifier applied to raw score (+10 expansion / +5 trending / -15 ranging).
3. Volume quality cap: if no volume context (neutral/unknown) the score
   is soft-capped to prevent phantom 95–100% readings.
4. Pattern conflict penalty applied in live pipeline (not just backtest).
5. Hard cap at 90 before returning — 100% is never statistically valid.

Weight scheme — dynamic redistribution when Harmonic has no signal:
─────────────────────────────────────────────────────────────────────
With Harmonic active:    Classical 30% | SMC 20% | Harmonic 35% | Volume 15%
Without Harmonic signal: Classical 50% | SMC 30% | Volume 20%
"""


MAX_CONFIDENCE = 90          # 100% is never statistically valid
VOLUME_QUALITY_CAP = 78      # cap when volume gives no confirmation


def compute_overall_bias(
    classical: dict,
    smc: dict,
    harmonic: dict,
    volume: dict,
    market_regime: dict | None = None,
    classical_patterns: list | None = None,
    harmonic_patterns: list | None = None,
) -> tuple:
    """Returns (overall_bias: str, overall_confidence: int, score_breakdown: dict)."""

    harmonic_active = harmonic.get("bias") in ["bullish", "bearish"]

    if harmonic_active:
        weights = {"classical": 30, "smc": 20, "harmonic": 35, "volume": 15}
    else:
        weights = {"classical": 50, "smc": 30, "harmonic": 0, "volume": 20}

    bias_scores = {"bullish": 0.0, "bearish": 0.0}
    breakdown = {}

    # ── Classical (ratio-weighted by signal agreement) ────────────────────────
    if classical["bias"] == "bullish":
        ratio = classical["bullish_signals"] / max(
            classical["bullish_signals"] + classical["bearish_signals"], 1
        )
        pts = weights["classical"] * ratio
        bias_scores["bullish"] += pts
        breakdown["classical"] = round(pts, 1)
    elif classical["bias"] == "bearish":
        ratio = classical["bearish_signals"] / max(
            classical["bullish_signals"] + classical["bearish_signals"], 1
        )
        pts = weights["classical"] * ratio
        bias_scores["bearish"] += pts
        breakdown["classical"] = round(-pts, 1)
    else:
        breakdown["classical"] = 0

    # ── SMC (now ratio-weighted by OB/FVG/structure signal count) ────────────
    # Previously binary (full weight or nothing). Now proportional to how many
    # SMC sub-signals agree, matching Classical's approach for consistency.
    # smc_analysis.py returns these as bullish_score/bearish_score (weighted
    # BOS/CHoCH/OB/FVG counters) — NOT bullish_signals/bearish_signals, which
    # never existed in that dict. Reading the wrong key silently defaulted both
    # to 0 every time, zeroing SMC's entire 20-35% weight regardless of how
    # confident smc["bias"] actually was (observed live: SMC reading 90%
    # confident while breakdown["smc"] was always exactly 0).
    smc_bull = int(smc.get("bullish_score", 0) or 0)
    smc_bear = int(smc.get("bearish_score", 0) or 0)
    smc_total = max(smc_bull + smc_bear, 1)
    if smc["bias"] == "bullish":
        smc_ratio = smc_bull / smc_total
        pts = weights["smc"] * smc_ratio
        bias_scores["bullish"] += pts
        breakdown["smc"] = round(pts, 1)
    elif smc["bias"] == "bearish":
        smc_ratio = smc_bear / smc_total
        pts = weights["smc"] * smc_ratio
        bias_scores["bearish"] += pts
        breakdown["smc"] = round(-pts, 1)
    else:
        breakdown["smc"] = 0

    # ── Harmonic (only when a pattern is present) ─────────────────────────────
    if harmonic_active:
        if harmonic["bias"] == "bullish":
            bias_scores["bullish"] += weights["harmonic"]
            breakdown["harmonic"] = weights["harmonic"]
        elif harmonic["bias"] == "bearish":
            bias_scores["bearish"] += weights["harmonic"]
            breakdown["harmonic"] = -weights["harmonic"]
    else:
        breakdown["harmonic"] = 0

    # ── Volume Profile ────────────────────────────────────────────────────────
    # "slightly_*" gets half weight, not the full 20% a plain "bullish"/"bearish"
    # gets — it was previously treated identically to the unqualified label,
    # meaning volume swung its ENTIRE weight on a categorical read with no
    # magnitude distinction, unlike Classical/SMC which are ratio-weighted by
    # signal strength. Observed live: 15/19 coins read bullish-leaning (often
    # only "slightly") in a broadly bearish 5m tape, so volume's full weight
    # was effectively voting against most coins' real direction (e.g. AAVE:
    # classical -27.8 and SMC -26.2 both firmly bearish, capped at 54 because
    # a "slightly_bullish" volume read carried the same 20 points as a firm one).
    if volume["bias"] == "bullish":
        bias_scores["bullish"] += weights["volume"]
        breakdown["volume"] = weights["volume"]
    elif volume["bias"] == "slightly_bullish":
        pts = weights["volume"] * 0.5
        bias_scores["bullish"] += pts
        breakdown["volume"] = pts
    elif volume["bias"] == "bearish":
        bias_scores["bearish"] += weights["volume"]
        breakdown["volume"] = -weights["volume"]
    elif volume["bias"] == "slightly_bearish":
        pts = weights["volume"] * 0.5
        bias_scores["bearish"] += pts
        breakdown["volume"] = -pts
    else:
        breakdown["volume"] = 0

    # ── Hard structural override ──────────────────────────────────────────────
    classical_trend = classical.get("moving_averages", {}).get("trend", "neutral")
    overall_bias = "bullish" if bias_scores["bullish"] > bias_scores["bearish"] else "bearish"

    if classical_trend == "strong_downtrend" and smc.get("bias") != "bullish":
        overall_bias = "bearish"

    raw_confidence = int(max(bias_scores["bullish"], bias_scores["bearish"]))
    breakdown["raw"] = raw_confidence

    # ── Phase 3: Regime modifier ──────────────────────────────────────────────
    # Single source of truth: market_regime.py owns the confidence_modifier value
    # for each regime. We only read it here — never re-derive it from the regime
    # name — so this number always matches what's shown anywhere else in the
    # report (e.g. PreTradeFilterService's regime_sweep_volume log).
    regime_modifier = int(market_regime.get("confidence_modifier", 0)) if market_regime else 0
    breakdown["regime_modifier"] = regime_modifier

    # ── Phase 3: Pattern conflict penalty (live pipeline) ────────────────────
    # Trigger-status tiered (0 / 5 / 10), mirroring
    # PreTradeFilterService::checkPatternAgreement() in PHP exactly — that
    # tiering was deliberately built to distinguish "both sides still just
    # forming" (LOW, informational only, 0 penalty) from a real confirmed
    # conflict, but this Python layer kept the older crude count-ratio version
    # (up to -20, firing on e.g. one bullish + one bearish pattern regardless
    # of whether either had actually triggered) and ran upstream of it on raw
    # confidence — so the two disagreed on the same patterns (observed live:
    # DASH/AAVE/LTC/SOL/SOXLB all -20 here while PHP scored the identical
    # setup as LOW/0). Patterns tagged status="invalidated" by
    # _annotate_pattern_status() (classical_analysis.py) have already been
    # broken against their own thesis by current price and must not count.
    # Harmonic patterns have no status/trigger concept — always "confirmed",
    # same as the PHP side.
    CONFIRMED_STATUSES = ("confirmed_breakout", "confirmed_breakdown")
    active_classical_patterns = [
        p for p in (classical_patterns or []) if p.get("status") != "invalidated"
    ]
    bull_patterns = []
    bear_patterns = []
    for p in active_classical_patterns:
        direction = (p.get("direction") or p.get("bias", "")).lower()
        confirmed = p.get("status") in CONFIRMED_STATUSES
        if direction in ["bullish", "up", "long"]:
            bull_patterns.append(confirmed)
        elif direction in ["bearish", "down", "short"]:
            bear_patterns.append(confirmed)
    for p in (harmonic_patterns or []):
        direction = (p.get("direction") or p.get("bias", "")).lower()
        if direction in ["bullish", "up", "long"]:
            bull_patterns.append(True)
        elif direction in ["bearish", "down", "short"]:
            bear_patterns.append(True)

    conflict_penalty = 0
    if bull_patterns and bear_patterns:
        bull_confirmed = any(bull_patterns)
        bear_confirmed = any(bear_patterns)
        if bull_confirmed and bear_confirmed:
            conflict_penalty = 10
        elif bull_confirmed or bear_confirmed:
            conflict_penalty = 5
        # else: LOW — both sides still just forming, informational only, 0 penalty
    breakdown["conflict_penalty"] = conflict_penalty

    # ── Phase 3: Volume quality soft cap ─────────────────────────────────────
    # No volume confirmation → cap at VOLUME_QUALITY_CAP to prevent phantom highs
    vol_bias = volume.get("bias", "neutral")
    volume_confirms = vol_bias in ["bullish", "bearish", "slightly_bullish", "slightly_bearish"]
    breakdown["volume_confirms"] = volume_confirms

    # ── Compose final score ───────────────────────────────────────────────────
    adjusted = raw_confidence + regime_modifier - conflict_penalty
    adjusted = max(0, adjusted)

    # Volume cap (soft)
    if not volume_confirms:
        adjusted = min(adjusted, VOLUME_QUALITY_CAP)
        breakdown["volume_cap_applied"] = True
    else:
        breakdown["volume_cap_applied"] = False

    # Hard ceiling — 100% is never statistically valid
    overall_confidence = min(int(adjusted), MAX_CONFIDENCE)
    breakdown["final"] = overall_confidence

    return overall_bias, overall_confidence, breakdown
