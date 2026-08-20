import os
import sys
import pandas as pd

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

class EarlyMoverStrategy(StrategyValidator):
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # Calculate 24h rolling metrics
        # 1 day = 288 candles of 5m
        df['close_24h_ago'] = df['close'].shift(288)
        df['change_24h'] = (df['close'] - df['close_24h_ago']) / df['close_24h_ago'] * 100
        
        # Step through the dataframe (check once every hour to avoid massive overlapping trades)
        step = 12 
        
        for i in range(288, len(df) - self.max_trade_duration, step):
            change = df.loc[i, 'change_24h']
            
            # Early Mover: Price moved up significantly (5% to 10%) matching production settings
            if 5.0 <= change <= 10.0:
                entry_price = df.loc[i, 'close']
                
                # For SL, let's place it below the lowest point of the last 24h
                low_24h = df['low'].iloc[i-288:i+1].min()
                
                # Buffer it slightly
                sl = low_24h * 0.999
                
                ops.append({
                    'index': i,
                    'bullish': True,
                    'sl': sl
                })
        
        return ops

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    
    # Out-Of-Sample Coins (for the confirmation test)
    test_symbols = ["SHIBUSDT", "TRXUSDT", "TONUSDT", "PEPEUSDT", "WIFUSDT", "STXUSDT", "FILUSDT", "ATOMUSDT", "ARUSDT", "RUNEUSDT"]
    
    print("========================================")
    print("Running Early Mover Strategy (5-10% pump, 1:1 RR, Max 2.5h) [OUT OF SAMPLE]")
    strategy = EarlyMoverStrategy(symbols=test_symbols, days=30, reward_ratio=1.0, max_trade_duration=30)
    strategy.run("Early Mover (5-10% - Fast Exit)")
