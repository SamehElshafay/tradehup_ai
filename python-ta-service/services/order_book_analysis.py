"""
Order Book Analysis — Binance Spot
====================================
Fetches the live order book from Binance (or Bybit / MEXC as fallback)
and computes execution-quality metrics:

  • Spread (bid/ask gap in Basis Points)
  • Bid Depth $ / Ask Depth $ near current price (±0.5%)
  • Imbalance Ratio (buy-side vs sell-side pressure)
  • Liquidity classification: liquid / moderate / thin
  • Spread classification: tight / normal / wide

Pure function — no side-effects, easy to test.
"""

import requests

# ── Thresholds ─────────────────────────────────────────────────────────────
SPREAD_TIGHT_BPS   = 5.0    # <= 5 bps → tight
SPREAD_WIDE_BPS    = 20.0   # > 20 bps → wide (anything between = normal)

DEPTH_LIQUID_USD   = 50_000   # >= $50k each side at ±0.5% → liquid
DEPTH_MODERATE_USD = 15_000   # >= $15k → moderate, below = thin

DEPTH_WINDOW_PCT   = 0.005    # ±0.5% from mid price for depth calculation
ORDER_BOOK_LIMIT   = 20       # top-N price levels to fetch


def fetch_order_book(symbol: str, exchange: str = "binance") -> dict:
    """
    Fetch the top ORDER_BOOK_LIMIT bid/ask levels from the exchange.
    Returns {"bids": [[price, qty], ...], "asks": [[price, qty], ...]}
    or an empty dict on failure.
    """
    exchange = exchange.lower()
    try:
        if exchange == "bybit":
            url = "https://api.bybit.com/v5/market/orderbook"
            params = {"category": "spot", "symbol": symbol.upper(), "limit": ORDER_BOOK_LIMIT}
            resp = requests.get(url, params=params, timeout=5, verify=False)
            resp.raise_for_status()
            data = resp.json()
            if data.get("retCode") != 0:
                return {}
            raw = data["result"]
            return {"bids": raw.get("b", []), "asks": raw.get("a", [])}

        elif exchange == "mexc":
            url = "https://api.mexc.com/api/v3/depth"
            params = {"symbol": symbol.upper(), "limit": ORDER_BOOK_LIMIT}
            resp = requests.get(url, params=params, timeout=5, verify=False)
            resp.raise_for_status()
            raw = resp.json()
            return {"bids": raw.get("bids", []), "asks": raw.get("asks", [])}

        else:
            # Default: Binance (redirected to MEXC due to Binance geoblocking on the VPS)
            url = "https://api.mexc.com/api/v3/depth"
            params = {"symbol": symbol.upper(), "limit": ORDER_BOOK_LIMIT}
            resp = requests.get(url, params=params, timeout=5, verify=False)
            resp.raise_for_status()
            raw = resp.json()
            return {"bids": raw.get("bids", []), "asks": raw.get("asks", [])}

    except Exception:
        return {}


def analyze_order_book(order_book: dict, current_price: float) -> dict:
    """
    Compute execution-quality metrics from raw order book data.

    Parameters
    ----------
    order_book   : {"bids": [[price_str, qty_str], ...], "asks": [...]}
    current_price: live mid-price reference

    Returns
    -------
    dict with keys: spread_bps, spread_status, bid_depth_usd, ask_depth_usd,
                    imbalance_ratio, imbalance_side, liquidity_status, summary
    """
    bids = order_book.get("bids", [])
    asks = order_book.get("asks", [])

    if not bids or not asks or current_price <= 0:
        return _no_data_result()

    try:
        best_bid = float(bids[0][0])
        best_ask = float(asks[0][0])
    except (IndexError, ValueError, TypeError):
        return _no_data_result()

    mid = (best_bid + best_ask) / 2.0
    if mid <= 0:
        return _no_data_result()

    # ── Spread ────────────────────────────────────────────────────────────
    spread_bps = ((best_ask - best_bid) / mid) * 10_000
    if spread_bps <= SPREAD_TIGHT_BPS:
        spread_status = "tight"
    elif spread_bps <= SPREAD_WIDE_BPS:
        spread_status = "normal"
    else:
        spread_status = "wide"

    # ── Depth within ±0.5% of mid ────────────────────────────────────────
    lower_bound = mid * (1 - DEPTH_WINDOW_PCT)
    upper_bound = mid * (1 + DEPTH_WINDOW_PCT)

    bid_depth_usd = sum(
        float(p) * float(q)
        for p, q in bids
        if float(p) >= lower_bound
    )
    ask_depth_usd = sum(
        float(p) * float(q)
        for p, q in asks
        if float(p) <= upper_bound
    )

    total_depth = bid_depth_usd + ask_depth_usd
    imbalance_ratio = round(bid_depth_usd / total_depth, 3) if total_depth > 0 else 0.5
    imbalance_side = "buy" if imbalance_ratio >= 0.55 else ("sell" if imbalance_ratio <= 0.45 else "neutral")

    # ── Liquidity classification ──────────────────────────────────────────
    min_side = min(bid_depth_usd, ask_depth_usd)
    if min_side >= DEPTH_LIQUID_USD:
        liquidity_status = "liquid"
    elif min_side >= DEPTH_MODERATE_USD:
        liquidity_status = "moderate"
    else:
        liquidity_status = "thin"

    return {
        "spread_bps":       round(spread_bps, 2),
        "spread_status":    spread_status,
        "best_bid":         round(best_bid, 8),
        "best_ask":         round(best_ask, 8),
        "bid_depth_usd":    round(bid_depth_usd, 2),
        "ask_depth_usd":    round(ask_depth_usd, 2),
        "imbalance_ratio":  imbalance_ratio,
        "imbalance_side":   imbalance_side,
        "liquidity_status": liquidity_status,
        "depth_window_pct": DEPTH_WINDOW_PCT * 100,
    }


def _no_data_result() -> dict:
    return {
        "spread_bps":       None,
        "spread_status":    "no_data",
        "best_bid":         None,
        "best_ask":         None,
        "bid_depth_usd":    None,
        "ask_depth_usd":    None,
        "imbalance_ratio":  None,
        "imbalance_side":   "no_data",
        "liquidity_status": "no_data",
        "depth_window_pct": DEPTH_WINDOW_PCT * 100,
    }
