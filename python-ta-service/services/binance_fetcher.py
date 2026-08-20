import requests
import pandas as pd
import hashlib
import time
from config import BINANCE_API_KEY, CACHE_TTL_SECONDS

# Base URLs for each exchange
EXCHANGE_BASES = {
    "binance": "https://api.binance.com",
    "bybit":   "https://api.bybit.com",
    "mexc":    "https://api.mexc.com",
}

# Simple in-memory cache
_cache: dict = {}


def _cache_key(symbol: str, exchange: str, interval: str, limit: int) -> str:
    raw = f"{exchange}:{symbol}:{interval}:{limit}"
    return hashlib.md5(raw.encode()).hexdigest()


def _get_from_cache(key: str):
    if key in _cache:
        entry = _cache[key]
        if time.time() - entry["ts"] < CACHE_TTL_SECONDS:
            return entry["data"]
        del _cache[key]
    return None


def _set_cache(key: str, data):
    _cache[key] = {"ts": time.time(), "data": data}


def fetch_ohlcv(symbol: str, exchange: str = "binance", interval: str = "4h", limit: int = 500) -> pd.DataFrame:
    """
    Fetch OHLCV candlestick data from Binance, Bybit, or MEXC.
    Returns a pandas DataFrame with columns: open_time, open, high, low, close, volume
    """
    exchange = exchange.lower()
    key = _cache_key(symbol, exchange, interval, limit)
    cached = _get_from_cache(key)
    if cached is not None:
        return pd.DataFrame(cached)

    if exchange == "bybit":
        df = _fetch_bybit(symbol, interval, limit)
    elif exchange == "mexc":
        df = _fetch_mexc(symbol, interval, limit)
    else:
        # Default: Binance
        df = _fetch_binance(symbol, interval, limit)

    _set_cache(key, df.to_dict("records"))
    return df


def _fetch_binance(symbol: str, interval: str, limit: int) -> pd.DataFrame:
    """Fetch OHLCV from Binance REST API with multi-endpoint fallback to bypass geoblocking."""
    endpoints = [
        "https://api.binance.com",
        "https://api3.binance.com",
        "https://api-gcp.binance.com",
        "https://api1.binance.com",
        "https://api2.binance.com"
    ]
    
    last_error = None
    for base in endpoints:
        url = f"{base}/api/v3/klines"
        params = {"symbol": symbol.upper(), "interval": interval, "limit": limit + 1}
        headers = {}
        if BINANCE_API_KEY:
            headers["X-MBX-APIKEY"] = BINANCE_API_KEY

        try:
            response = requests.get(url, params=params, headers=headers, timeout=5, verify=False)
            response.raise_for_status()
            raw = response.json()
            
            df = pd.DataFrame(raw, columns=[
                "open_time", "open", "high", "low", "close", "volume",
                "close_time", "quote_volume", "trades",
                "taker_buy_base", "taker_buy_quote", "ignore"
            ])
            df = df[["open_time", "open", "high", "low", "close", "volume", "quote_volume"]].copy()
            df["open_time"] = pd.to_datetime(pd.to_numeric(df["open_time"]), unit="ms")
            for col in ["open", "high", "low", "close", "volume"]:
                df[col] = pd.to_numeric(df[col])
            return df.iloc[:-1].reset_index(drop=True)
        except Exception as e:
            last_error = e
            continue
            
    raise last_error


def _fetch_bybit(symbol: str, interval: str, limit: int) -> pd.DataFrame:
    """Fetch OHLCV from Bybit REST API v5."""
    # Convert standard interval to Bybit format
    interval_map = {
        "1m": "1", "3m": "3", "5m": "5", "15m": "15", "30m": "30",
        "1h": "60", "2h": "120", "4h": "240", "6h": "360", "12h": "720",
        "1d": "D", "1w": "W", "1M": "M"
    }
    bybit_interval = interval_map.get(interval, "240")

    url = "https://api.bybit.com/v5/market/kline"
    params = {
        "category": "spot",
        "symbol": symbol.upper(),
        "interval": bybit_interval,
        "limit": min(limit + 1, 1000)  # +1: drop the still-forming candle below
    }
    response = requests.get(url, params=params, timeout=10, verify=False)
    response.raise_for_status()
    raw = response.json()

    if raw["retCode"] != 0:
        raise ValueError(f"Bybit API Error: {raw['retMsg']}")

    data = raw["result"]["list"]
    # Bybit returns newest first, so we reverse it
    df = pd.DataFrame(data, columns=["open_time", "open", "high", "low", "close", "volume", "turnover"])
    df = df.iloc[::-1].reset_index(drop=True)
    df = df[["open_time", "open", "high", "low", "close", "volume", "turnover"]].copy()
    df.rename(columns={"turnover": "quote_volume"}, inplace=True)
    df["open_time"] = pd.to_datetime(pd.to_numeric(df["open_time"]), unit="ms")
    for col in ["open", "high", "low", "close", "volume", "quote_volume"]:
        df[col] = pd.to_numeric(df[col])
    return df.iloc[:-1].reset_index(drop=True)  # drop the still-forming last candle


def _fetch_mexc(symbol: str, interval: str, limit: int) -> pd.DataFrame:
    """Fetch OHLCV from MEXC REST API."""
    url = "https://api.mexc.com/api/v3/klines"
    params = {
        "symbol": symbol.upper(),
        "interval": interval,
        "limit": min(limit + 1, 1000)  # +1: drop the still-forming candle below
    }
    response = requests.get(url, params=params, timeout=10, verify=False)
    response.raise_for_status()
    raw = response.json()

    df = pd.DataFrame(raw, columns=[
        "open_time", "open", "high", "low", "close", "volume",
        "close_time", "quote_volume", "trades",
        "taker_buy_base", "taker_buy_quote", "ignore"
    ])
    df = df[["open_time", "open", "high", "low", "close", "volume", "quote_volume"]].copy()
    df["open_time"] = pd.to_datetime(pd.to_numeric(df["open_time"]), unit="ms")
    for col in ["open", "high", "low", "close", "volume", "quote_volume"]:
        df[col] = pd.to_numeric(df[col])
    return df.iloc[:-1].reset_index(drop=True)  # drop the still-forming last candle


def fetch_ticker_price(symbol: str, exchange: str = "binance") -> float:
    """Fetch current price for a symbol from the appropriate exchange."""
    exchange = exchange.lower()

    if exchange == "bybit":
        url = "https://api.bybit.com/v5/market/tickers"
        response = requests.get(url, params={"category": "spot", "symbol": symbol.upper()}, timeout=5, verify=False)
        response.raise_for_status()
        data = response.json()
        return float(data["result"]["list"][0]["lastPrice"])

    elif exchange == "mexc":
        url = "https://api.mexc.com/api/v3/ticker/price"
        response = requests.get(url, params={"symbol": symbol.upper()}, timeout=5, verify=False)
        response.raise_for_status()
        return float(response.json()["price"])

    else:
        # Default: Binance
        url = "https://api.binance.com/api/v3/ticker/price"
        response = requests.get(url, params={"symbol": symbol.upper()}, timeout=5, verify=False)
        response.raise_for_status()
        return float(response.json()["price"])


def fetch_top_coins(limit: int = 50) -> list:
    """Fetch top trading pairs by volume (USDT pairs only) from Binance."""
    url = "https://api.binance.com/api/v3/ticker/24hr"
    response = requests.get(url, timeout=10, verify=False)
    response.raise_for_status()
    all_tickers = response.json()

    usdt_pairs = [
        t for t in all_tickers
        if t["symbol"].endswith("USDT") and float(t["quoteVolume"]) > 0
    ]
    sorted_pairs = sorted(usdt_pairs, key=lambda x: float(x["quoteVolume"]), reverse=True)

    result = []
    for t in sorted_pairs[:limit]:
        result.append({
            "symbol": t["symbol"],
            "price": float(t["lastPrice"]),
            "price_change_24h": float(t["priceChangePercent"]),
            "volume_24h": float(t["quoteVolume"]),
            "high_24h": float(t["highPrice"]),
            "low_24h": float(t["lowPrice"]),
        })
    return result
