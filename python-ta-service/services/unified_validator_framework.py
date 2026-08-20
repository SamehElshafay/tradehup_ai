import os
import sys
import numpy as np
import pandas as pd
import random
from typing import List, Dict, Optional

sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from services.amd_validator import _fetch_ohlcv_paginated

class StrategyResult:
    def __init__(self, trades: List[Dict]):
        self.trades = trades
        
    def generate_report(self, name: str):
        if not self.trades:
            print(f"\n--- {name} ---")
            print("No trades found.")
            return

        df = pd.DataFrame(self.trades)
        
        n_trades = len(df)
        wins = len(df[df['pnl_r'] > 0])
        losses = len(df[df['pnl_r'] <= 0])
        win_rate = (wins / n_trades) * 100 if n_trades > 0 else 0
        
        # Risk/Reward Simulation
        # Assume Risk = 1R (1% of account), Reward = 1.5R (1.5% of account)
        # We will track an equity curve starting at 100
        equity = 100.0
        peak = 100.0
        max_drawdown = 0.0
        
        consecutive_losses = 0
        max_consecutive_losses = 0
        
        total_profit_r = 0.0
        total_loss_r = 0.0
        
        times_in_trade = []
        
        for idx, trade in df.iterrows():
            times_in_trade.append(trade['duration_candles'])
            
            pnl = trade['pnl_r']
            equity += pnl
            
            if pnl > 0:
                total_profit_r += pnl
                consecutive_losses = 0
            else:
                total_loss_r += abs(pnl)
                consecutive_losses += 1
                if consecutive_losses > max_consecutive_losses:
                    max_consecutive_losses = consecutive_losses
                    
            if equity > peak:
                peak = equity
            drawdown = ((peak - equity) / peak) * 100
            if drawdown > max_drawdown:
                max_drawdown = drawdown
                
        profit_factor = (total_profit_r / total_loss_r) if total_loss_r > 0 else float('inf')
        
        avg_time_candles = np.mean(times_in_trade)
        median_time_candles = np.median(times_in_trade)
        
        total_sl_hits = len(df[df['pnl_r'] == -1.0])
        stop_hunts = len(df[df['was_stop_hunted'] == True])
        stop_hunt_pct = (stop_hunts / total_sl_hits) * 100 if total_sl_hits > 0 else 0
        
        print(f"\n--- {name} ---")
        print(f"Total Trades (n): {n_trades}")
        print(f"Win Rate:         {win_rate:.1f}%")
        print(f"Profit Factor:    {profit_factor:.2f}")
        print(f"Max Drawdown:     {max_drawdown:.2f}% (Assuming 1% risk per trade)")
        print(f"Max Cons. Losses: {max_consecutive_losses} trades")
        print(f"Avg Time in Trade: {avg_time_candles:.1f} candles ({avg_time_candles*5:.0f} mins)")
        print(f"Med Time in Trade: {median_time_candles:.1f} candles ({median_time_candles*5:.0f} mins)")
        print(f"Net R (Profit):   {'+' if (total_profit_r - total_loss_r) >= 0 else ''}{total_profit_r - total_loss_r:.2f}R")
        print(f"Total SL Hits:    {total_sl_hits}")
        print(f"Stop Hunted:      {stop_hunts} trades ({stop_hunt_pct:.1f}% of SL hits reversed to TP within 30m)")

class StrategyValidator:
    def __init__(self, symbols: List[str], days: int, timeframe="5m", max_trade_duration=100, reward_ratio=1.5):
        self.symbols = symbols
        self.days = days
        self.timeframe = timeframe
        self.max_trade_duration = max_trade_duration
        self.reward_ratio = reward_ratio
        
    def find_opportunities(self, df: pd.DataFrame) -> List[Dict]:
        """
        Must return a list of trade setups.
        Each setup dict must have:
        - 'index': the row index in df where the setup is triggered
        - 'bullish': boolean (True for long, False for short)
        - 'sl': exact price level for Stop Loss
        """
        raise NotImplementedError("Subclasses must implement this method")

    def run(self, name="Strategy Evaluation") -> StrategyResult:
        all_trades = []
        
        print(f"Fetching data for {len(self.symbols)} symbols over {self.days} days...")
        for symbol in self.symbols:
            df = _fetch_ohlcv_paginated(symbol, self.timeframe, self.days)
            if df is None or len(df) < 500:
                continue
                
            df = df.reset_index(drop=True)
            opportunities = self.find_opportunities(df)
            
            for opp in opportunities:
                entry_idx = opp['index']
                if entry_idx >= len(df) - 1:
                    continue
                    
                entry_price = df.loc[entry_idx, 'close']
                sl_price = opp['sl']
                expected_bullish = opp['bullish']
                
                # Enforce minimum risk (e.g. 0.2%) to avoid exact tick-outs on tiny ranges
                min_risk_pct = 0.002
                
                if expected_bullish:
                    risk = entry_price - sl_price
                    risk = max(risk, entry_price * min_risk_pct)
                    tp_price = entry_price + (risk * self.reward_ratio)
                    sl_price = entry_price - risk # recalibrated SL based on min risk
                else:
                    risk = sl_price - entry_price
                    risk = max(risk, entry_price * min_risk_pct)
                    tp_price = entry_price - (risk * self.reward_ratio)
                    sl_price = entry_price + risk
                    
                pnl_r = None
                eval_idx = entry_idx
                
                for j in range(entry_idx + 1, min(entry_idx + 1 + self.max_trade_duration, len(df))):
                    current_high = df.loc[j, 'high']
                    current_low = df.loc[j, 'low']
                    
                    if expected_bullish:
                        if current_low <= sl_price:
                            pnl_r = -1.0
                            eval_idx = j
                            break
                        if current_high >= tp_price:
                            pnl_r = self.reward_ratio
                            eval_idx = j
                            break
                    else:
                        if current_high >= sl_price:
                            pnl_r = -1.0
                            eval_idx = j
                            break
                        if current_low <= tp_price:
                            pnl_r = self.reward_ratio
                            eval_idx = j
                            break
                
                # If no SL/TP hit within duration, exit at market
                if pnl_r is None:
                    eval_idx = min(entry_idx + self.max_trade_duration, len(df) - 1)
                    end_price = df.loc[eval_idx, 'close']
                    if expected_bullish:
                        pnl_r = (end_price - entry_price) / risk
                    else:
                        pnl_r = (entry_price - end_price) / risk
                
                # Stop Hunt Diagnostic
                was_stop_hunted = False
                if pnl_r == -1.0:
                    for k in range(eval_idx + 1, min(eval_idx + 1 + 6, len(df))): # Check next 30 mins (6 candles)
                        if expected_bullish and df.loc[k, 'high'] >= tp_price:
                            was_stop_hunted = True
                            break
                        if not expected_bullish and df.loc[k, 'low'] <= tp_price:
                            was_stop_hunted = True
                            break
                        
                all_trades.append({
                    'symbol': symbol,
                    'entry_time': df.loc[entry_idx, 'open_time'],
                    'pnl_r': pnl_r,
                    'duration_candles': eval_idx - entry_idx,
                    'bullish': expected_bullish,
                    'was_stop_hunted': was_stop_hunted
                })
                
        result = StrategyResult(all_trades)
        result.generate_report(name)
        return result

# ==========================================
# SANITY CHECK: Random Strategy
# ==========================================
class RandomStrategy(StrategyValidator):
    def find_opportunities(self, df: pd.DataFrame) -> List[Dict]:
        ops = []
        # Simulate 1 random trade per day per coin
        step = 288 # 1 day on 5m chart
        for i in range(100, len(df) - self.max_trade_duration, step):
            is_bullish = random.choice([True, False])
            entry_price = df.loc[i, 'close']
            
            # Use fixed 0.5% risk
            if is_bullish:
                sl = entry_price * 0.995
            else:
                sl = entry_price * 1.005
                
            ops.append({
                'index': i,
                'bullish': is_bullish,
                'sl': sl
            })
        return ops

if __name__ == "__main__":
    import warnings
    warnings.filterwarnings("ignore")
    
    print("Running Framework Sanity Check (Random Entry, 1:1.5 RR)")
    print("Expected: Win Rate ~40%, Profit Factor ~1.0, Net R ~0")
    
    symbols = ["BTCUSDT", "ETHUSDT", "SOLUSDT", "BNBUSDT", "XRPUSDT"]
    random_validator = RandomStrategy(symbols=symbols, days=30)
    random_validator.run("Random Sanity Check")
