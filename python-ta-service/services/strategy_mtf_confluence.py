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

class MTFConfluenceStrategy(StrategyValidator):
    def __init__(self, symbols, days, mode="baseline_a"):
        # modes: baseline_a, baseline_b, v1_pullback, v2_divergence
        super().__init__(symbols, days, reward_ratio=1.5, max_trade_duration=30)
        self.mode = mode
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # --- PREPARE DATA ---
        
        # Layer 1: 1H EMA 50
        # Resample to 1H, calculate EMA 50
        df_1h = df.resample('1h', on='open_time').last()
        df_1h['ema_50'] = df_1h['close'].ewm(span=50, adjust=False).mean()
        # Shift by 1 to prevent lookahead bias (use previous closed hour's EMA)
        df_1h_shifted = df_1h[['ema_50']].shift(1)
        # Merge back to 5m
        df = pd.merge_asof(df, df_1h_shifted, left_on='open_time', right_index=True, direction='backward')
        df.rename(columns={'ema_50': '1h_ema_50'}, inplace=True)
        
        # Layer 2: 15m VWAP (Anchored at 13:00 UTC)
        # VWAP math is identical on 5m if anchor is the same.
        df['typical_price'] = (df['high'] + df['low'] + df['close']) / 3
        df['pv'] = df['typical_price'] * df['volume']
        # Group by daily session starting at 13:00 UTC
        df['session_id'] = (df['open_time'] - pd.Timedelta(hours=13)).dt.date
        df['cum_pv'] = df.groupby('session_id')['pv'].cumsum()
        df['cum_vol'] = df.groupby('session_id')['volume'].cumsum()
        df['15m_vwap'] = df['cum_pv'] / df['cum_vol']
        
        # Layer 3 V1: 5m Volume Pullback
        df['5m_ema_20'] = df['close'].ewm(span=20, adjust=False).mean()
        df['5m_vol_ma_20'] = df['volume'].rolling(window=20).mean()
        
        # Layer 3 V2: 5m RSI Divergence
        df['rsi'] = calculate_rsi(df['close'], 14)
        
        lookback = 30
        
        for i in range(lookback * 2, len(df) - self.max_trade_duration):
            
            # Basic Safety Checks
            if pd.isna(df.loc[i, '1h_ema_50']) or pd.isna(df.loc[i, '15m_vwap']):
                continue
                
            current_close = df.loc[i, 'close']
            current_low = df.loc[i, 'low']
            
            # --- LAYER EVALUATIONS ---
            
            # Layer 1: 1H Trend
            layer1_pass = current_close > df.loc[i, '1h_ema_50']
            
            # Layer 2: 15m VWAP
            layer2_pass = current_close > df.loc[i, '15m_vwap']
            
            # Layer 3 V1: Volume Pullback
            l3_v1_pass = False
            ema_20 = df.loc[i, '5m_ema_20']
            if current_low <= ema_20 and current_close > ema_20: # Touched/pierced but closed above
                if df.loc[i, 'volume'] >= 1.5 * df.loc[i, '5m_vol_ma_20']: # Volume spike
                    l3_v1_pass = True
                    
            # Layer 3 V2: RSI Divergence
            l3_v2_pass = False
            window_lows = df['low'].iloc[i-lookback:i]
            prev_low_val = window_lows.min()
            if current_low < prev_low_val:
                prev_low_idx = window_lows.idxmin()
                prev_low_rsi = df.loc[prev_low_idx, 'rsi']
                if df.loc[i, 'rsi'] > prev_low_rsi:
                    l3_v2_pass = True
                    
            # --- DECISION LOGIC ---
            
            trigger = False
            if self.mode == "baseline_a":
                trigger = layer1_pass
            elif self.mode == "baseline_b":
                trigger = layer1_pass and layer2_pass
            elif self.mode == "v1_pullback":
                trigger = layer1_pass and layer2_pass and l3_v1_pass
            elif self.mode == "v2_divergence":
                trigger = layer1_pass and layer2_pass and l3_v2_pass
                
            if trigger:
                # Stop Loss: Below the local 5m swing low (let's say lowest of last 3 candles)
                local_low = df['low'].iloc[i-2:i+1].min()
                sl = local_low * 0.999
                risk_pct = (current_close - sl) / current_close
                
                # Constrain risk between 0.3% and 1.0% to match user criteria
                if risk_pct < 0.003:
                    sl = current_close * 0.997
                elif risk_pct > 0.01:
                    sl = current_close * 0.99
                    
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
    print("Running MTF Confluence (Graduated Baselines)")
    print("========================================")
    
    # Baseline A
    b_a = MTFConfluenceStrategy(symbols=train_symbols, days=60, mode="baseline_a")
    b_a.run("BASELINE A (1H EMA 50 Only)")
    
    # Baseline B
    b_b = MTFConfluenceStrategy(symbols=train_symbols, days=60, mode="baseline_b")
    b_b.run("BASELINE B (1H EMA 50 + 15m VWAP)")
    
    # Strategy V1
    v1 = MTFConfluenceStrategy(symbols=train_symbols, days=60, mode="v1_pullback")
    v1.run("STRATEGY V1 (Trend + VWAP + Vol Pullback)")
    
    # Strategy V2
    v2 = MTFConfluenceStrategy(symbols=train_symbols, days=60, mode="v2_divergence")
    v2.run("STRATEGY V2 (Trend + VWAP + RSI Divergence)")
