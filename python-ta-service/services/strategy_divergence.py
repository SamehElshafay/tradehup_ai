import os
import sys
import pandas as pd
import numpy as np

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

def calculate_rsi_wilder(series: pd.Series, period: int = 14) -> pd.Series:
    delta = series.diff()
    gain = delta.where(delta > 0, 0.0)
    loss = -delta.where(delta < 0, 0.0)
    avg_gain = gain.ewm(alpha=1/period, min_periods=period, adjust=False).mean()
    avg_loss = loss.ewm(alpha=1/period, min_periods=period, adjust=False).mean()
    rs = avg_gain / avg_loss
    rsi = 100 - (100 / (1 + rs))
    return rsi

class DivergenceStrategy(StrategyValidator):
    def __init__(self, symbols, days, rsi_period=14, mode="baseline"):
        # mode can be: "baseline" (buy on new low), "divergence" (buy on LL + HL RSI + green candle)
        super().__init__(symbols, days, reward_ratio=1.5, max_trade_duration=30)
        self.rsi_period = rsi_period
        self.mode = mode
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # Calculate RSI
        df['rsi'] = calculate_rsi_wilder(df['close'], self.rsi_period)
        
        # Lookback window for previous low
        lookback = 30
        
        # We need to find the index of the minimum low in the rolling window
        # pandas rolling idxmin is not directly supported natively without apply, 
        # but we can do it efficiently
        
        for i in range(lookback * 2, len(df) - self.max_trade_duration):
            # The previous window: from i-lookback to i-1
            window_lows = df['low'].iloc[i-lookback:i]
            prev_low_val = window_lows.min()
            prev_low_idx = window_lows.idxmin()
            
            # The RSI at the exact moment of the previous low
            prev_low_rsi = df.loc[prev_low_idx, 'rsi']
            
            current_low = df.loc[i, 'low']
            current_rsi = df.loc[i, 'rsi']
            
            # Condition: Current Low is lower than the lowest low of the last 30 candles
            if current_low < prev_low_val:
                
                if self.mode == "baseline":
                    # Blindly buy the new low
                    entry_price = df.loc[i, 'close']
                    sl = current_low * 0.999
                    risk_pct = (entry_price - sl) / entry_price
                    if 0.003 <= risk_pct <= 0.01:
                        ops.append({'index': i, 'bullish': True, 'sl': sl})
                        
                elif self.mode == "divergence":
                    # Condition: RSI is HIGHER than the RSI at the previous low
                    if current_rsi > prev_low_rsi:
                        # Condition: Confirmation candle (closes green)
                        if df.loc[i, 'close'] > df.loc[i, 'open']:
                            entry_price = df.loc[i, 'close']
                            sl = current_low * 0.999
                            risk_pct = (entry_price - sl) / entry_price
                            if 0.003 <= risk_pct <= 0.01:
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
    print("Running RSI Divergence Strategy on 20 Coins / 60 Days")
    
    # Baseline
    baseline = DivergenceStrategy(symbols=train_symbols, days=60, mode="baseline")
    baseline.run("BASELINE (Price Lower Low)")
    
    # Strategy RSI 14
    strategy_14 = DivergenceStrategy(symbols=train_symbols, days=60, rsi_period=14, mode="divergence")
    strategy_14.run("STRATEGY (RSI 14 Divergence + Green Confirm)")
    
    # Strategy RSI 7
    strategy_7 = DivergenceStrategy(symbols=train_symbols, days=60, rsi_period=7, mode="divergence")
    strategy_7.run("STRATEGY (RSI 7 Divergence + Green Confirm)")
