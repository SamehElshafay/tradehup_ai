import pandas as pd
from services.binance_fetcher import fetch_ohlcv

df = fetch_ohlcv("BTCUSDT", "15m", 300)
if df is not None:
    opens = df["open"].values
    closes = df["close"].values
    highs = df["high"].values
    lows = df["low"].values
    times = df["open_time"].tolist()

    threshold = 0.0025

    print("--- DETECTED OBS WITH MITIGATION STARTING AT i + 4 ---")
    for i in range(5, len(df) - 4):
        future_change = (closes[i + 3] - closes[i]) / closes[i]
        
        # Bullish
        if closes[i] < opens[i] and future_change > threshold:
            top = float(opens[i])
            bottom = float(closes[i])
            mitigated = False
            mitigating_candle = None
            for j in range(i + 4, len(df)):
                if lows[j] <= bottom:
                    mitigated = True
                    mitigating_candle = (times[j], lows[j])
                    break
            if not mitigated:
                print(f"Bullish OB at {times[i]} (bottom={bottom}): Active!")

        # Bearish
        if closes[i] > opens[i] and future_change < -threshold:
            top = float(closes[i])
            bottom = float(opens[i])
            mitigated = False
            mitigating_candle = None
            for j in range(i + 4, len(df)):
                if highs[j] >= top:
                    mitigated = True
                    mitigating_candle = (times[j], highs[j])
                    break
            if not mitigated:
                print(f"Bearish OB at {times[i]} (top={top}): Active!")
