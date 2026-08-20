import os
import sys
import pandas as pd

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.unified_validator_framework import StrategyValidator

class VolumeAnomalyStrategy(StrategyValidator):
    def __init__(self, symbols, days, volume_multiplier=1.5, run_baseline=False):
        super().__init__(symbols, days)
        self.volume_multiplier = volume_multiplier
        self.run_baseline = run_baseline
        
    def find_opportunities(self, df: pd.DataFrame) -> list:
        ops = []
        
        # Calculate 24h rolling metrics
        # 1 day = 288 candles of 5m
        df['close_24h_ago'] = df['close'].shift(288)
        df['change_24h'] = (df['close'] - df['close_24h_ago']) / df['close_24h_ago'] * 100
        
        # Calculate Volume Anomaly
        # Sum of volume over the last 24h
        df['vol_24h'] = df['volume'].rolling(288).sum()
        # Average 24h volume over the past 7 days (7 * 288 = 2016 candles)
        df['avg_vol_24h_7d'] = df['vol_24h'].rolling(2016).mean()
        
        # Step through the dataframe (e.g. check once every hour to avoid massive overlapping trades)
        step = 12 
        
        for i in range(2016, len(df) - self.max_trade_duration, step):
            change = df.loc[i, 'change_24h']
            vol_24h = df.loc[i, 'vol_24h']
            avg_vol = df.loc[i, 'avg_vol_24h_7d']
            
            # Baseline: Price moved slightly up (1% to 7%) matching production settings
            if 1.0 <= change <= 7.0:
                
                # If we are running baseline, we ignore the volume check
                if self.run_baseline:
                    condition_met = True
                else:
                    # Check for Volume Anomaly: Volume is significantly higher than normal
                    condition_met = avg_vol > 0 and (vol_24h / avg_vol) > self.volume_multiplier
                    
                if condition_met:
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
    
    # In-Sample Coins
    train_symbols = ["BTCUSDT", "ETHUSDT", "SOLUSDT", "BNBUSDT", "XRPUSDT", "DOGEUSDT", "ADAUSDT", "AVAXUSDT", "LINKUSDT", "DOTUSDT"]
    
    # Out-Of-Sample Coins (for the confirmation test)
    test_symbols = ["SHIBUSDT", "TRXUSDT", "TONUSDT", "PEPEUSDT", "WIFUSDT", "STXUSDT", "FILUSDT", "ATOMUSDT", "ARUSDT", "RUNEUSDT"]
    
    print("========================================")
    print("Running Volume Anomaly (BASELINE - Price 1-7%, no volume filter) [OUT OF SAMPLE]")
    baseline = VolumeAnomalyStrategy(symbols=test_symbols, days=30, run_baseline=True)
    baseline.run("Volume Anomaly (BASELINE - OOS)")
    
    print("========================================")
    print("Running Volume Anomaly (STRATEGY - Price 1-7% + Volume > 1.5x Avg) [OUT OF SAMPLE]")
    strategy = VolumeAnomalyStrategy(symbols=test_symbols, days=30, volume_multiplier=1.5, run_baseline=False)
    strategy.run("Volume Anomaly (STRATEGY 1.5x - OOS)")
