import os
import sys
import pandas as pd
import numpy as np

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

class LiquiditySweepStrategy(StrategyValidator):
    def __init__(self, symbols, days, n_candles=20, mode="baseline"):
        # mode can be: "baseline" (blind buy on new low), "sweep" (wick + 1-candle recovery)
        super().__init__(symbols, days, reward_ratio=1.5, max_trade_duration=30)
        self.n_candles = n_candles
        self.mode = mode
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # Calculate recent low rolling window
        # We shift by 1 so the recent low doesn't include the current candle
        df['recent_low'] = df['low'].rolling(self.n_candles).min().shift(1)
        
        # For sweep calculations
        df['body'] = abs(df['open'] - df['close'])
        df['lower_wick'] = df[['open', 'close']].min(axis=1) - df['low']
        
        for i in range(self.n_candles, len(df) - self.max_trade_duration):
            recent_low = df.loc[i, 'recent_low']
            if pd.isna(recent_low):
                continue
                
            current_low = df.loc[i, 'low']
            
            # Trigger: Current candle breaks the recent low
            if current_low < recent_low:
                
                if self.mode == "baseline":
                    # Baseline: Buy immediately blindly
                    entry_price = df.loc[i, 'close']
                    sl = current_low * 0.999 # SL below the new low
                    
                    risk_pct = (entry_price - sl) / entry_price
                    if 0.003 <= risk_pct <= 0.01:
                        ops.append({'index': i, 'bullish': True, 'sl': sl})
                        
                elif self.mode == "sweep":
                    # Condition 1: Long wick (>= 2x body)
                    body = df.loc[i, 'body']
                    lower_wick = df.loc[i, 'lower_wick']
                    
                    if lower_wick >= (2.0 * body):
                        # Condition 2: 1-Candle Fast Recovery (Closes above the broken recent low)
                        if df.loc[i, 'close'] > recent_low:
                            entry_price = df.loc[i, 'close']
                            sl = current_low * 0.999
                            
                            risk_pct = (entry_price - sl) / entry_price
                            if 0.003 <= risk_pct <= 0.01:
                                ops.append({'index': i, 'bullish': True, 'sl': sl})
                                
        return ops

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    
    # 20 Coins, 60 Days (Robust In-Sample Test)
    train_symbols = [
        "BTCUSDT", "ETHUSDT", "SOLUSDT", "BNBUSDT", "XRPUSDT", 
        "DOGEUSDT", "ADAUSDT", "AVAXUSDT", "LINKUSDT", "DOTUSDT",
        "MATICUSDT", "LTCUSDT", "BCHUSDT", "UNIUSDT", "ATOMUSDT",
        "NEARUSDT", "APTUSDT", "OPUSDT", "ARBUSDT", "INJUSDT"
    ]
    
    # Test N=15, 20, 30
    for n in [15, 20, 30]:
        print(f"\n{'='*60}")
        print(f"Testing N={n} Candles ({n*5} mins) on 20 Coins / 60 Days")
        print(f"{'='*60}")
        
        baseline = LiquiditySweepStrategy(symbols=train_symbols, days=60, n_candles=n, mode="baseline")
        baseline.run(f"BASELINE (Blind Buy) N={n}")
        
        sweep = LiquiditySweepStrategy(symbols=train_symbols, days=60, n_candles=n, mode="sweep")
        sweep.run(f"STRATEGY (Sweep + 1-Candle Rec) N={n}")
