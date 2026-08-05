"""
Mirrors AnalysisController::getTimeframeConstraints() / getExpiryHours() on
the PHP side, so the Python backtester can simulate the SAME TP1/TP2/TP3/SL
distances and holding window a real trade would actually use — instead of a
simplified "did price move X%" proxy. Keep in sync with the PHP source if
those constants change.
"""

# interval -> (max_tp3, tp_step, sl_ratio) — fractions of price
_CONSTRAINTS = {
    "1m": (0.02, 0.005, 0.008), "5m": (0.02, 0.005, 0.008),
    "15m": (0.04, 0.01, 0.015), "30m": (0.04, 0.01, 0.015),
    "1h": (0.08, 0.02, 0.025),
    "4h": (0.15, 0.04, 0.05),
    "1d": (0.25, 0.08, 0.08),
    "1w": (0.50, 0.15, 0.15),
}
_DEFAULT_CONSTRAINTS = (0.15, 0.04, 0.05)

# interval -> expiry in hours
_EXPIRY_HOURS = {
    "1m": 1, "5m": 1,
    "15m": 4, "30m": 4,
    "1h": 8,
    "4h": 24,
    "1d": 72,
    "1w": 168,
}
_DEFAULT_EXPIRY_HOURS = 24

_INTERVAL_MINUTES = {
    "1m": 1, "3m": 3, "5m": 5, "15m": 15, "30m": 30,
    "1h": 60, "2h": 120, "4h": 240, "6h": 360, "8h": 480, "12h": 720,
    "1d": 1440, "1w": 10080,
}


def get_constraints(interval: str) -> dict:
    max_tp3, tp_step, sl_ratio = _CONSTRAINTS.get(interval, _DEFAULT_CONSTRAINTS)
    return {"max_tp3": max_tp3, "tp_step": tp_step, "sl_ratio": sl_ratio}


def get_expiry_candles(interval: str) -> int:
    hours = _EXPIRY_HOURS.get(interval, _DEFAULT_EXPIRY_HOURS)
    minutes_per_candle = _INTERVAL_MINUTES.get(interval, 15)
    return max(1, int(hours * 60 / minutes_per_candle))
