import os
import sys
import numpy as np
import pandas as pd
from datetime import datetime

# Adjust path to import services
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from services.amd_validator import _fetch_ohlcv_paginated
from services.classical_analysis import run_classical_analysis

def calculate_atr_pandas(df, period=14):
    high_low = df['high'] - df['low']
    high_close = np.abs(df['high'] - df['close'].shift())
    low_close = np.abs(df['low'] - df['close'].shift())
    ranges = pd.concat([high_low, high_close, low_close], axis=1)
    true_range = np.max(ranges, axis=1)
    atr = true_range.rolling(period).mean()
    return atr

def run_micro_validator(
    # OUT-OF-SAMPLE TEST: Completely different set of 20 coins
    symbols=["SHIBUSDT", "TRXUSDT", "TONUSDT", "PEPEUSDT", "WIFUSDT", "STXUSDT", "FILUSDT", "ATOMUSDT", "ARUSDT", "RUNEUSDT", "GALAUSDT", "GRTUSDT", "ORDIUSDT", "JUPUSDT", "PYTHUSDT", "FETUSDT", "TIAUSDT", "SEIUSDT", "ALGOUSDT", "FLOWUSDT"],
    days=60,
    window_size=15, # 15 candles * 5m = 75 minutes
    trigger_window=3,
    max_trade_duration=100, # Max 100 candles to hit TP/SL (~8.3 hours)
    volume_ratio=0.7,
    cooldown_candles=9 # 45 minutes cooldown
):
    print(f"Fetching data for {len(symbols)} symbols over {days} days...")
    
    results = []
    
    for symbol in symbols:
        print(f"Processing {symbol}...")
        df = _fetch_ohlcv_paginated(symbol, "5m", days)
        if df is None or len(df) < 500:
            continue
            
        df = df.reset_index(drop=True)
        df['atr_14'] = calculate_atr_pandas(df, 14)
        
        # Calculate volume for each window by summing the 5m volumes
        # To do this efficiently, we can use a rolling sum, but we want non-overlapping blocks.
        
        i = 300 # Start after 24h (288 candles) + some buffer
        
        while i < len(df) - window_size - trigger_window - max_trade_duration:
            # 1. Early Mover Check
            # Price change over the last 24h (288 candles)
            current_close = df.loc[i, 'close']
            past_close = df.loc[i - 288, 'close']
            change_pct = (current_close - past_close) / past_close
            
            if not (0.05 <= change_pct <= 0.10):
                i += window_size # Step by non-overlapping window
                continue
                
            # 2. Volume Check
            window_df = df.iloc[i : i + window_size]
            current_vol = window_df['volume'].sum()
            
            # Average volume of the past 10 windows (10 * 15 = 150 candles)
            past_vol_df = df.iloc[i - 150 : i]
            avg_vol = past_vol_df['volume'].sum() / 10
            
            if avg_vol == 0 or (current_vol / avg_vol) > volume_ratio:
                i += window_size
                continue
                
            # 3. Valid Micro-Accumulation Found! Calculate Range/ATR
            acc_high = window_df['high'].max()
            acc_low = window_df['low'].min()
            acc_range = acc_high - acc_low
            
            atr = df.loc[i + window_size - 1, 'atr_14']
            if atr <= 0:
                i += window_size
                continue
                
            ratio = acc_range / atr
            
            # --- BASELINE TRADE EVALUATION ---
            # If we just traded the Early Mover breakout without waiting for a Sweep
            base_entry = df.loc[i + window_size - 1, 'close']
            # Assume trend is bullish because Early Mover requires +5% to +10%
            base_sl = acc_low * (1 - 0.0005)
            base_risk = max(base_entry - base_sl, base_entry * 0.002)
            base_tp = base_entry + (base_risk * 1.5)
            
            base_win = None
            for j in range(i + window_size, min(i + window_size + max_trade_duration, len(df))):
                if df.loc[j, 'low'] <= base_sl:
                    base_win = False
                    break
                if df.loc[j, 'high'] >= base_tp:
                    base_win = True
                    break
            if base_win is None:
                end_price = df.loc[min(i + window_size + max_trade_duration - 1, len(df) - 1), 'close']
                base_win = end_price > base_entry
                
            # 4. Check for Sweep
            manip_df = df.iloc[i + window_size : i + window_size + trigger_window]
            
            swept_high = False
            swept_low = False
            sweep_idx = -1
            
            for idx, row in manip_df.iterrows():
                if row['high'] > acc_high:
                    swept_high = True
                    sweep_idx = idx
                    break
                if row['low'] < acc_low:
                    swept_low = True
                    sweep_idx = idx
                    break
            
            if not swept_high and not swept_low:
                # No sweep, just record the ratio to understand the distribution
                results.append({
                    'symbol': symbol,
                    'time': df.loc[i, 'open_time'],
                    'ratio': ratio,
                    'sweep': False,
                    'win': None,
                    'baseline_win': base_win
                })
                i += window_size
                continue
            
            # 5. Check if it returned inside the range (Valid Manipulation)
            return_idx = -1
            for j in range(sweep_idx + 1, min(sweep_idx + 1 + trigger_window, len(df))):
                close = df.loc[j, 'close']
                if acc_low <= close <= acc_high:
                    return_idx = j
                    break
            
            if return_idx == -1:
                # Sweep failed to return
                results.append({
                    'symbol': symbol,
                    'time': df.loc[i, 'open_time'],
                    'ratio': ratio,
                    'sweep': False,
                    'win': None,
                    'baseline_win': base_win
                })
                i += window_size
                continue
            
            # 6. Valid Sweep! Evaluate Win/Loss with real SL/TP
            expected_bullish = swept_low # if it swept low, we expect it to go up
            entry_price = df.loc[return_idx, 'close']
            
            # Use a slightly buffered wick as SL to avoid exact tick-outs
            buffer_pct = 0.0005 # 0.05%
            
            if expected_bullish:
                sl = df.loc[sweep_idx, 'low'] * (1 - buffer_pct)
                risk = entry_price - sl
                # If risk is extremely tight (like 1 tick), enforce minimum risk to avoid immediate stop out
                min_risk = entry_price * 0.002 # 0.2%
                risk = max(risk, min_risk)
                tp = entry_price + (risk * 1.5)
            else:
                sl = df.loc[sweep_idx, 'high'] * (1 + buffer_pct)
                risk = sl - entry_price
                min_risk = entry_price * 0.002
                risk = max(risk, min_risk)
                tp = entry_price - (risk * 1.5)
                
            win = None
            eval_idx = return_idx
            
            for j in range(return_idx + 1, return_idx + max_trade_duration):
                current_high = df.loc[j, 'high']
                current_low = df.loc[j, 'low']
                
                if expected_bullish:
                    # Check SL first
                    if current_low <= sl:
                        win = False
                        eval_idx = j
                        break
                    # Check TP
                    if current_high >= tp:
                        win = True
                        eval_idx = j
                        break
                else:
                    if current_high >= sl:
                        win = False
                        eval_idx = j
                        break
                    if current_low <= tp:
                        win = True
                        eval_idx = j
                        break
            
            # If trade didn't hit SL or TP by max_duration, check if we're in profit at the end
            if win is None:
                eval_idx = return_idx + max_trade_duration - 1
                end_price = df.loc[eval_idx, 'close']
                if expected_bullish:
                    win = end_price > entry_price
                else:
                    win = end_price < entry_price
            
            results.append({
                'symbol': symbol,
                'time': df.loc[i, 'open_time'],
                'ratio': ratio,
                'sweep': True,
                'bullish': expected_bullish,
                'win': win,
                'baseline_win': base_win
            })
            
            # Apply Cooldown
            i = eval_idx + cooldown_candles

    print(f"\n--- Results Summary ---")
    res_df = pd.DataFrame(results)
    if res_df.empty:
        print("No early mover micro-accumulations found!")
        return

    print(f"Total valid quiet windows found: {len(res_df)}")
    base_win_rate = res_df['baseline_win'].mean() * 100
    print(f"Baseline Win Rate (No Sweep, Just Early Mover): {base_win_rate:.1f}% (n={len(res_df)})")
    
    sweeps = res_df[res_df['sweep'] == True]
    print(f"\nTotal valid sweeps found: {len(sweeps)}")
    
    if not sweeps.empty:
        win_rate = sweeps['win'].mean() * 100
        print(f"Overall Sweep Win Rate: {win_rate:.1f}% (n={len(sweeps)})")
        
    print(f"\n--- OUT-OF-SAMPLE VALIDATION ---")
    print(f"Testing hardcoded threshold: 3.08x ATR")
    
    tight_sweeps = sweeps[sweeps['ratio'] <= 3.08]
    if len(tight_sweeps) > 0:
        wr = tight_sweeps['win'].mean() * 100
        print(f"Win Rate for tight sweeps (<= 3.08x ATR): {wr:.1f}% (n={len(tight_sweeps)})")
    else:
        print(f"No tight sweeps found (<= 3.08x ATR) in the out-of-sample dataset.")

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    run_micro_validator()
