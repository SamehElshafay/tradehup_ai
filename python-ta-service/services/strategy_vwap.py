import os
import sys
import pandas as pd
import numpy as np

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

class VWAPStrategy(StrategyValidator):
    def __init__(self, symbols, days, mode="baseline"):
        # mode can be: "baseline", "cross", "bounce"
        super().__init__(symbols, days, reward_ratio=1.5, max_trade_duration=30)
        self.mode = mode
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # Convert open_time to datetime
        df['datetime'] = pd.to_datetime(df['open_time'], unit='ms')
        
        # Calculate Volume Spike (Current volume > 2x the 20-candle MA)
        df['vol_ma_20'] = df['volume'].rolling(20).mean()
        df['vol_spike'] = df['volume'] > (df['vol_ma_20'] * 2.0)
        
        # Calculate Session-Anchored VWAP (Anchored at 13:00 UTC - NY Open)
        # We need cumulative (Typical Price * Volume) and cumulative Volume, resetting at 13:00
        
        df['typical_price'] = (df['high'] + df['low'] + df['close']) / 3
        df['pv'] = df['typical_price'] * df['volume']
        
        # Create a session ID that increments every time the hour is 13 and minute is 0
        df['is_ny_open'] = (df['datetime'].dt.hour == 13) & (df['datetime'].dt.minute == 0)
        df['session_id'] = df['is_ny_open'].cumsum()
        
        # Group by session ID to calculate cumulative sums
        df['cum_pv'] = df.groupby('session_id')['pv'].cumsum()
        df['cum_vol'] = df.groupby('session_id')['volume'].cumsum()
        df['vwap'] = df['cum_pv'] / df['cum_vol']
        
        # Step through the dataframe
        # We start at 50 to have enough data for the MA
        # We skip the first few candles of the session to let VWAP stabilize
        
        for i in range(50, len(df) - self.max_trade_duration):
            if not df.loc[i, 'vol_spike']:
                continue
                
            # Baseline: Buy purely on volume spike
            if self.mode == "baseline":
                entry_price = df.loc[i, 'close']
                # Swing low of last 10 candles
                sl = df['low'].iloc[i-10:i+1].min() * 0.999
                
                # Enforce SL limits (0.3% to 1.0%)
                risk_pct = (entry_price - sl) / entry_price
                if 0.003 <= risk_pct <= 0.01:
                    ops.append({'index': i, 'bullish': True, 'sl': sl})
                    
            elif self.mode == "cross":
                vwap = df.loc[i, 'vwap']
                close = df.loc[i, 'close']
                open_price = df.loc[i, 'open']
                prev_close = df.loc[i-1, 'close']
                
                # Cross logic: Previous close was below VWAP, Current close is above VWAP
                if prev_close < vwap and close > vwap and close > open_price:
                    # Let's ensure it's not the very first candle of the session
                    if df.loc[i, 'session_id'] == df.loc[i-5, 'session_id']:
                        entry_price = close
                        sl = df['low'].iloc[i-5:i+1].min() * 0.999
                        risk_pct = (entry_price - sl) / entry_price
                        if 0.003 <= risk_pct <= 0.01:
                            ops.append({'index': i, 'bullish': True, 'sl': sl})
                            
            elif self.mode == "bounce":
                vwap = df.loc[i, 'vwap']
                close = df.loc[i, 'close']
                open_price = df.loc[i, 'open']
                low = df.loc[i, 'low']
                prev_close = df.loc[i-1, 'close']
                
                # Bounce logic: Price is generally above VWAP, dips to touch VWAP, and closes green
                if prev_close > vwap and low <= vwap and close > vwap and close > open_price:
                    if df.loc[i, 'session_id'] == df.loc[i-5, 'session_id']:
                        entry_price = close
                        sl = df['low'].iloc[i-5:i+1].min() * 0.999
                        risk_pct = (entry_price - sl) / entry_price
                        if 0.003 <= risk_pct <= 0.01:
                            ops.append({'index': i, 'bullish': True, 'sl': sl})
                            
        return ops

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    
    # Let's use a solid In-Sample list
    train_symbols = ["BTCUSDT", "ETHUSDT", "SOLUSDT", "BNBUSDT", "XRPUSDT", "DOGEUSDT", "ADAUSDT", "AVAXUSDT", "LINKUSDT", "DOTUSDT"]
    
    print("========================================")
    print("Running VWAP Strategy (BASELINE: Volume Spike Only)")
    baseline = VWAPStrategy(symbols=train_symbols, days=30, mode="baseline")
    baseline.run("VWAP (BASELINE)")
    
    print("========================================")
    print("Running VWAP Strategy (CROSS: Under VWAP -> Over VWAP w/ Vol Spike)")
    cross = VWAPStrategy(symbols=train_symbols, days=30, mode="cross")
    cross.run("VWAP (CROSS)")
    
    print("========================================")
    print("Running VWAP Strategy (BOUNCE: Dips to VWAP -> Closes Green w/ Vol Spike)")
    bounce = VWAPStrategy(symbols=train_symbols, days=30, mode="bounce")
    bounce.run("VWAP (BOUNCE)")
