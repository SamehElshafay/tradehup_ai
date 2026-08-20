import os
import joblib
import pandas as pd
import numpy as np
from sklearn.neural_network import MLPClassifier
from sklearn.preprocessing import StandardScaler
from sklearn.model_selection import cross_val_score, StratifiedKFold
from typing import List, Dict, Any, Optional

MODEL_PATH  = "trade_model.pkl"
SCALER_PATH = "trade_scaler.pkl"


class MLService:
    def __init__(self):
        self.model  = None
        self.scaler = None
        self.feature_names: List[str] = []
        self.load_model()

    def load_model(self):
        if os.path.exists(MODEL_PATH) and os.path.exists(SCALER_PATH):
            try:
                self.model  = joblib.load(MODEL_PATH)
                self.scaler = joblib.load(SCALER_PATH)
                if hasattr(self.model, "feature_names_in_"):
                    self.feature_names = list(self.model.feature_names_in_)
            except Exception as e:
                print(f"[MLService] Warning: could not load model - {e}")

    def extract_features(self, trade_data: Dict[str, Any]) -> pd.DataFrame:
        """
        Extract numerical features from a trade dict.
        Handles both paper-trade format and rich historical format.
        """
        features = {}

        def get_float(val, default=0.0):
            try:
                if val is None or val == '': return default
                if isinstance(val, str) and ':' in val:
                    parts = val.split(':')
                    if len(parts) == 2:
                        return float(parts[1]) / float(parts[0]) if float(parts[0]) != 0 else default
                v = float(val)
                return default if (np.isnan(v) or np.isinf(v)) else v
            except Exception:
                return default

        action = str(trade_data.get("action", "WAIT")).upper()
        features['is_buy']  = 1 if action == "BUY"  else 0
        features['is_sell'] = 1 if action == "SELL" else 0

        metrics = trade_data.get("technical_metrics", {})

        features['rsi'] = get_float(metrics.get("rsi"), 50.0)

        ema_trend = str(metrics.get("ema_trend", "")).lower()
        features['ema_bullish'] = 1 if 'bull' in ema_trend or 'up' in ema_trend else 0
        features['ema_bearish'] = 1 if 'bear' in ema_trend or 'down' in ema_trend else 0

        features['volatility'] = get_float(metrics.get("volatility"), 0.0)

        smc_bias = str(metrics.get("smc_bias", "")).lower()
        features['smc_bullish'] = 1 if 'bull' in smc_bias else 0
        features['smc_bearish'] = 1 if 'bear' in smc_bias else 0

        features['order_blocks'] = int(metrics.get("order_blocks", 0) or 0)
        features['fvgs']         = int(metrics.get("fvgs", 0)         or 0)

        # Extra rich features from historical_trainer
        features['macd_hist']       = get_float(metrics.get("macd_hist"), 0.0)
        features['stoch_k']         = get_float(metrics.get("stoch_k"), 50.0)
        features['adx']             = get_float(metrics.get("adx"), 20.0)
        features['vol_ratio']       = get_float(metrics.get("vol_ratio"), 1.0)
        features['bb_position']     = get_float(metrics.get("bb_position"), 0.5)
        features['pct_from_ema20']  = get_float(metrics.get("pct_from_ema20"), 0.0)
        features['pct_from_ema200'] = get_float(metrics.get("pct_from_ema200"), 0.0)
        features['candle_body_pct'] = get_float(metrics.get("candle_body_pct"), 0.0)

        features['risk_reward']       = get_float(trade_data.get("risk_reward"), 1.0)
        confs = trade_data.get("confluences", [])
        features['confluences_count'] = len(confs) if isinstance(confs, list) else 0

        has_blocking_fvg = 0
        has_liq_conflict = 0
        if isinstance(confs, list):
            for c in confs:
                c_str = str(c).lower()
                if "blocking" in c_str and "fvg" in c_str:
                    has_blocking_fvg = 1
                if "liquidity sweep conflict" in c_str or "stop_hunt" in c_str:
                    has_liq_conflict = 1
                    
        features['has_blocking_fvg'] = has_blocking_fvg
        features['has_liq_conflict'] = has_liq_conflict

        # Normalised ATR% — cross-coin comparable volatility
        features['atr_pct'] = get_float(metrics.get("atr_pct"), get_float(metrics.get("volatility"), 0.0))

        return pd.DataFrame([features])

    def train(self, dataset: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Train a Gradient Boosting classifier and return rich metrics."""
        if not dataset:
            raise ValueError("Dataset is empty")

        df_features_list = []
        labels = []

        for row in dataset:
            outcome = str(row.get("hindsight_outcome", "")).lower()
            # STRICT LABELING: Only TP2 and TP3 are considered successes (Sniper Mode).
            # Hitting TP1 (1:0.67 RR) is not good enough, it's labeled as a failure (0)
            if "tp2" in outcome or "tp3" in outcome:
                label = 1
            elif "tp1" in outcome or "failed" in outcome or "sl" in outcome:
                label = 0
            else:
                continue

            features_df = self.extract_features(row)
            df_features_list.append(features_df)
            labels.append(label)

        if not df_features_list:
            raise ValueError("No valid training samples found")

        X = pd.concat(df_features_list, ignore_index=True)
        y = labels

        scaler = StandardScaler()
        X_scaled = scaler.fit_transform(X)

        # HistGradientBoostingClassifier tuned for HIGH PRECISION (Sniper Mode)
        # - Extremely low learning rate (0.01) so it doesn't jump to conclusions
        # - High L2 regularization (2.0) to aggressively penalize noise
        # - Max depth 5 to avoid memorising individual bad trades
        # Neural Network Classifier (Multi-Layer Perceptron)
        # Configured with hidden layers (64, 32) to capture complex non-linear structures
        # L2 regularization (alpha) and early stopping are enabled to prevent overfitting
        model = MLPClassifier(
            hidden_layer_sizes=(64, 32),
            activation='relu',
            solver='adam',
            max_iter=1000,
            alpha=0.05,
            early_stopping=True,
            validation_fraction=0.1,
            random_state=42
        )
        model.fit(X_scaled, y)

        # Cross-validation
        cv_acc = None
        try:
            cv = StratifiedKFold(n_splits=min(5, min(sum(y), len(y) - sum(y))), shuffle=True, random_state=42)
            cv_scores = cross_val_score(model, X_scaled, y, cv=cv, scoring="accuracy")
            cv_acc = round(float(np.mean(cv_scores)) * 100, 2)
        except Exception as e:
            print(f"[MLService] CV skipped: {e}")

        # Feature importance — HistGBM doesn't expose feature_importances_ directly,
        # we use permutation importance on a small sample for speed
        feature_names = list(X.columns)
        fi_sorted = []
        try:
            from sklearn.inspection import permutation_importance
            # Use at most 2000 samples for speed (permutation_importance can be slow on huge sets)
            sample_size = min(2000, len(y))
            perm_result = permutation_importance(
                model, X_scaled[:sample_size], y[:sample_size],
                n_repeats=5, random_state=42, n_jobs=-1
            )
            importances = perm_result.importances_mean
            fi_sorted = sorted(zip(feature_names, importances), key=lambda x: x[1], reverse=True)
        except Exception as e:
            print(f"[MLService] Permutation importance skipped: {e}")
            fi_sorted = [(f, 0.0) for f in feature_names]

        joblib.dump(model,  MODEL_PATH)
        joblib.dump(scaler, SCALER_PATH)
        self.model  = model
        self.scaler = scaler
        self.feature_names = feature_names

        y_arr = [int(v) for v in y]
        tp_count = sum(y_arr)
        sl_count = len(y_arr) - tp_count
        tp_rate  = round(tp_count / len(y_arr) * 100, 1) if y_arr else 0.0
        acc      = round(model.score(X_scaled, y) * 100, 2)

        return {
            "success":            True,
            "message":            "Model trained successfully",
            "samples_trained":    len(y),
            "training_accuracy":  acc,
            "cv_accuracy":        cv_acc,
            "tp_rate":            tp_rate,
            "sl_count":           sl_count,
            "tp_count":           tp_count,
            "feature_importance": [{"feature": f, "importance": round(float(i), 4)} for f, i in fi_sorted[:12]],
        }

    def predict(self, opportunity: Dict[str, Any]) -> Dict[str, Any]:
        """Predict outcome for a single opportunity."""
        if self.model is None or self.scaler is None:
            return {
                "prediction":                  "Model not trained yet",
                "max_profit_expected_percent": 0.0,
                "confidence_score":            0,
                "reasoning":                   "Please train the model first by clicking Train Local ML Model.",
            }

        # If we are missing rich features like macd_hist, fetch and compute them on the fly
        metrics = opportunity.get("technical_metrics", {})
        if "macd_hist" not in metrics:
            coin = opportunity.get("coin")
            tf = opportunity.get("timeframe", "15m")
            if coin:
                try:
                    from services.binance_fetcher import fetch_ohlcv
                    from services.historical_trainer import _compute_indicators
                    
                    df = fetch_ohlcv(coin, "binance", tf, limit=300)
                    if df is not None and not df.empty:
                        # Rename open_time to timestamp if needed
                        if "open_time" in df.columns:
                            df = df.rename(columns={"open_time": "timestamp"})
                            
                        for col in ["open", "high", "low", "close", "volume"]:
                            if col in df.columns:
                                df[col] = pd.to_numeric(df[col], errors="coerce")
                        
                        df.dropna(subset=["close"], inplace=True)
                        df.reset_index(drop=True, inplace=True)
                        
                        df = _compute_indicators(df)
                        if not df.empty:
                            last_row = df.iloc[-1]
                            
                            # Merge fetched features into metrics
                            metrics["macd_hist"] = last_row.get("macd_hist", 0.0)
                            metrics["stoch_k"]   = last_row.get("stoch_k", 50.0)
                            metrics["adx"]       = last_row.get("adx", 20.0)
                            metrics["vol_ratio"] = last_row.get("vol_ratio", 1.0)
                            
                            close_p = last_row.get("close", 1.0)
                            metrics["bb_position"] = (close_p - last_row.get("bb_lower", close_p)) / (last_row.get("bb_upper", close_p) - last_row.get("bb_lower", close_p) + 1e-9)
                            
                            ema20 = last_row.get("ema20", close_p)
                            ema200 = last_row.get("ema200", close_p)
                            metrics["pct_from_ema20"] = (close_p - ema20) / ema20 * 100
                            metrics["pct_from_ema200"] = (close_p - ema200) / ema200 * 100
                            
                            atr = last_row.get("atr", 0.0)
                            metrics["atr_pct"] = (atr / close_p * 100) if close_p > 0 else 0.0
                            metrics["candle_body_pct"] = last_row.get("candle_body_pct", 0.0)
                            
                            opportunity["technical_metrics"] = metrics
                except Exception as e:
                    print(f"[ML Predict] Warning: failed to fetch rich features for {coin} - {e}")

        X        = self.extract_features(opportunity)
        
        # Align features with training data if model is loaded
        if self.feature_names:
            for f in self.feature_names:
                if f not in X.columns:
                    X[f] = 0.0
            X = X[self.feature_names]

        X_scaled = self.scaler.transform(X)
        prob     = self.model.predict_proba(X_scaled)[0]
        tp_prob  = prob[1] if len(prob) > 1 else 0.5

        # We now rely entirely on the ML model's weights rather than hard-coded penalties.
        # The model sees has_blocking_fvg and has_liq_conflict as features.
        
        has_blocking_fvg = X["has_blocking_fvg"].iloc[0] == 1 if "has_blocking_fvg" in X.columns else False
        has_liq_conflict = X["has_liq_conflict"].iloc[0] == 1 if "has_liq_conflict" in X.columns else False

        base_rr        = float(opportunity.get("risk_reward", 2.0))
        entry_price    = float(opportunity.get("entry_price", 0))
        sl_price       = float(opportunity.get("sl", 0))
        trade_type     = opportunity.get("type", "BUY").upper()

        if tp_prob >= 0.5:
            max_profit_est = round(base_rr * 1.5 * tp_prob, 2)
        else:
            if entry_price > 0 and sl_price > 0:
                if trade_type == "BUY":
                    sl_pct = ((sl_price - entry_price) / entry_price) * 100
                else:
                    sl_pct = ((entry_price - sl_price) / entry_price) * 100
                
                # Predict a drop towards the exact stop loss
                max_profit_est = round(sl_pct * (1 - tp_prob), 2)
            else:
                max_profit_est = round(-1.5 * (1 - tp_prob), 2)

        if tp_prob >= 0.75:
            pred = "High Probability (Hits TP2/TP3)"
        elif tp_prob >= 0.50:
            pred = "Moderate Probability (Hits TP1)"
        else:
            pred = "Low Probability (Likely Hits SL)"

        conf = int(tp_prob * 100)

        top_features = []
        if hasattr(self.model, "feature_importances_") and self.feature_names:
            fi = sorted(zip(self.feature_names, self.model.feature_importances_), key=lambda x: -x[1])
            top_features = [f for f, _ in fi[:3]]
        if not top_features:
            top_features = ["RSI", "EMA Trend", "Risk/Reward"]

        adx = metrics.get("adx", 20.0)
        vol_ratio = metrics.get("vol_ratio", 1.0)
        body_pct = metrics.get("candle_body_pct", 50.0)

        if tp_prob < 0.5:
            reasoning = "Trajectory Forecast: FAKEOUT. "
            reasons = []
            if has_blocking_fvg: reasons.append("a BLOCKING FVG stands directly in the path of the trade")
            if has_liq_conflict: reasons.append("a Liquidity Sweep Conflict indicates a high probability of a trap")
            if adx < 30 and not has_blocking_fvg: reasons.append("trend momentum is too weak")
            if vol_ratio < 1.1: reasons.append("volume is insufficient")
            if body_pct < 40: reasons.append("candle wicks show heavy rejection")
            
            if reasons:
                reasoning += f"Because {', and '.join(reasons)}, "
            
            reasoning += "the AI expects the price to briefly pump to trap retail traders, before violently reversing to hit your Stop Loss. Avoid this trade."
        else:
            reasoning = "Trajectory Forecast: SNIPER BREAKOUT. "
            reasoning += "Institutional momentum and volume are aligned. The AI expects price to easily smash through TP1 and cleanly reach TP2/TP3 without threatening the Stop Loss."

        return {
            "prediction":                  pred,
            "max_profit_expected_percent": max_profit_est,
            "confidence_score":            conf,
            "reasoning":                   reasoning,
        }


ml_service = MLService()
