import os
import sys
import pandas as pd
import numpy as np

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

def calculate_rsi(series: pd.Series, period: int = 14) -> pd.Series:
    delta = series.diff()
    gain = delta.where(delta > 0, 0.0)
    loss = -delta.where(delta < 0, 0.0)
    avg_gain = gain.ewm(alpha=1/period, min_periods=period, adjust=False).mean()
    avg_loss = loss.ewm(alpha=1/period, min_periods=period, adjust=False).mean()
    rs = avg_gain / avg_loss
    return 100 - (100 / (1 + rs))

class Strategy15mConfluence(StrategyValidator):
    def __init__(self, symbols, days, mode="baseline_b"):
        # modes: baseline_b, v2_divergence
        # max_trade_duration=10 candles on 15m = 150 minutes
        super().__init__(symbols, days, timeframe="15m", reward_ratio=1.5, max_trade_duration=10)
        self.mode = mode
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # --- PREPARE DATA ---
        
        # Layer 1: 1H EMA 50
        df_1h = df.resample('1h', on='open_time').last()
        df_1h['ema_50'] = df_1h['close'].ewm(span=50, adjust=False).mean()
        df_1h_shifted = df_1h[['ema_50']].shift(1)
        df = pd.merge_asof(df, df_1h_shifted, left_on='open_time', right_index=True, direction='backward')
        df.rename(columns={'ema_50': '1h_ema_50'}, inplace=True)
        
        # Layer 2: 15m VWAP (Anchored at 13:00 UTC)
        df['typical_price'] = (df['high'] + df['low'] + df['close']) / 3
        df['pv'] = df['typical_price'] * df['volume']
        df['session_id'] = (df['open_time'] - pd.Timedelta(hours=13)).dt.date
        df['cum_pv'] = df.groupby('session_id')['pv'].cumsum()
        df['cum_vol'] = df.groupby('session_id')['volume'].cumsum()
        df['15m_vwap'] = df['cum_pv'] / df['cum_vol']
        
        # Layer 3 V2: 15m RSI Divergence
        df['rsi'] = calculate_rsi(df['close'], 14)
        
        lookback = 30
        
        # Pre-calculate simple masks for massive speedup
        mask_valid = df['1h_ema_50'].notna() & df['15m_vwap'].notna()
        mask_l1 = df['close'] > df['1h_ema_50']
        mask_l2 = df['close'] > df['15m_vwap']
        
        # rolling_low is the minimum of the previous `lookback` candles
        rolling_low = df['low'].shift(1).rolling(window=lookback).min()
        mask_new_low = df['low'] < rolling_low
        
        for i in range(lookback * 2, len(df) - self.max_trade_duration):
            
            if not mask_valid.iloc[i]:
                continue
                
            trigger = False
            
            if self.mode == "baseline_b":
                trigger = mask_l1.iloc[i] and mask_l2.iloc[i]
                
            elif self.mode == "v2_divergence":
                if mask_l1.iloc[i] and mask_l2.iloc[i] and mask_new_low.iloc[i]:
                    # We only do the expensive idxmin if all other conditions pass
                    window_lows = df['low'].iloc[i-lookback:i]
                    prev_low_idx = window_lows.idxmin()
                    prev_low_rsi = df.loc[prev_low_idx, 'rsi']
                    if df.loc[i, 'rsi'] > prev_low_rsi:
                        trigger = True
                        
            if trigger:
                current_close = df.loc[i, 'close']
                # Stop Loss: Below the local 15m swing low (lowest of last 2 candles)
                local_low = df['low'].iloc[i-1:i+1].min()
                sl = local_low * 0.999
                risk_pct = (current_close - sl) / current_close
                
                # Constrain risk between 0.3% and 1.5% for 15m
                if risk_pct < 0.003:
                    sl = current_close * 0.997
                elif risk_pct > 0.015:
                    sl = current_close * 0.985
                    
                ops.append({'index': i, 'bullish': True, 'sl': sl})
                
        return ops

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    
    # 20 Coins, 60 Days
    train_symbols = [
        "BTCUSDT", "ETHUSDT", "SOLUSDT", "BNBUSDT", "XRPUSDT", 
        "DOGEUSDT", "ADAUSDT", "AVAXUSDT", "LINKUSDT", "DOTUSDT",
        "MATICUSDT", "LTCUSDT", "BCHUSDT", "UNIUSDT", "ATOMUSDT",
        "NEARUSDT", "APTUSDT", "OPUSDT", "ARBUSDT", "INJUSDT"
    ]
    
    print("========================================")
    print("Running 15m MTF Confluence")
    print("========================================")
    
    # Baseline B (1H Trend + 15m VWAP) - EXECUTED ON 15M CHART
    b_b = Strategy15mConfluence(symbols=train_symbols, days=60, mode="baseline_b")
    b_b.run("BASELINE B (1H EMA 50 + 15m VWAP - 15m Execution)")
    
    # Strategy V2 (1H Trend + 15m VWAP + 15m RSI Divergence)
    v2 = Strategy15mConfluence(symbols=train_symbols, days=60, mode="v2_divergence")
    v2.run("STRATEGY V2 (Trend + VWAP + 15m RSI Divergence)")
