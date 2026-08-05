<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Exception;

class OpenRouterService {
    private string $baseUrl;
    private string $apiKey;
    private string $provider;
    public string $defaultModel;
    public string $chatModel;
    public string $newsModel;
    private string $settingsFile;

    public function __construct() {
        // ⚠️ settingsFile MUST be set before calling loadSettings()
        $this->settingsFile = storage_path('app/ai_settings.json');

        $settings = $this->loadSettings();

        $this->provider = $settings['ai_provider'] ?? 'ollama';
        
        $keyName = match($this->provider) {
            'gemini'     => 'gemini_api_key',
            'wecai'      => 'wecai_api_key',
            default      => 'openrouter_api_key',
        };
        $encryptedKey = $settings[$keyName] ?? ($settings['api_key'] ?? ''); // Fallback to old api_key if new keys aren't set yet

        // Safely decrypt the stored API key
        $apiKey = '';
        if (!empty($encryptedKey)) {
            try {
                $apiKey = Crypt::decryptString($encryptedKey);
            } catch (DecryptException $e) {
                // Key may have been saved unencrypted in a previous version — use as-is
                $apiKey = $encryptedKey;
            }
        }

        if ($this->provider === 'gemini') {
            $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/openai';
            $this->apiKey  = $apiKey;
        } elseif ($this->provider === 'openrouter') {
            $this->baseUrl = 'https://openrouter.ai/api/v1';
            $this->apiKey  = $apiKey;
        } elseif ($this->provider === 'wecai') {
            $this->baseUrl = 'https://ai.worldelitecircle.com/api';
            // WeCai uses a fixed bearer token — no user API key needed
            $this->apiKey  = 'sk-my-super-secret-key-12345';
        } else {
            $this->baseUrl = env('OPENROUTER_BASE_URL', 'http://localhost:11434/v1');
            $this->apiKey  = '';
        }

        // WeCai only supports one model: qwen2.5:3b
        if ($this->provider === 'wecai') {
            $this->defaultModel = 'qwen2.5:3b';
            $this->chatModel    = 'qwen2.5:3b';
            $this->newsModel    = 'qwen2.5:3b';
        } else {
            $this->defaultModel = $settings['analysis_model'] ?? env('OPENROUTER_DEFAULT_MODEL', 'qwen2.5:latest');
            $this->chatModel    = $settings['chat_model'] ?? env('OPENROUTER_CHAT_MODEL', 'qwen2.5:1.5b');
            $this->newsModel    = $settings['news_model'] ?? 'qwen2.5:latest';
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Public API
    // ─────────────────────────────────────────────────────────────────

    public function generateRecommendation(array $analysisData, string $symbol, string $timeframe): array {
        if ($this->isLocalMode()) {
            return $this->generateMockRecommendation($analysisData, $symbol, $timeframe);
        }

        $settings = $this->loadSettings();
        $prompt   = $this->buildTradingPrompt($analysisData, $symbol, $timeframe, $settings);
        $response = $this->callLLM(
            model:       $this->defaultModel,
            userMessage: $prompt,
            systemPrompt:'You are an expert crypto trading analyst. Always respond with valid JSON only. No markdown. No explanation.',
            maxTokens:   (int) ($settings['analysis_max_tokens'] ?? 1200),
            temperature: (float) ($settings['analysis_temperature'] ?? 0.1),
            keepAlive:   $settings['keep_alive'] ?? '10m'
        );

        try {
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $cleaned = $matches[0];
            } else {
                $cleaned = $response;
            }
            $cleaned = preg_replace('/(\d),(\d)/', '$1$2', $cleaned);
            $result  = json_decode(trim($cleaned), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $trimmed = trim($cleaned);
                if (substr($trimmed, -1) !== '}') {
                    throw new Exception("The AI response was cut off before finishing (Token Limit Reached). Please increase 'analysis_max_tokens' in settings. Raw output ends with: " . substr($trimmed, -60));
                }
                throw new Exception('Invalid JSON format from AI. The model failed to follow the required JSON structure. Raw output: ' . $response);
            }
            return $result;
        } catch (Exception $e) {
            Log::error('Failed to parse AI recommendation', ['error' => $e->getMessage(), 'response' => $response ?? '']);
            throw $e;
        }
    }

    public function generateRecommendationFromPrompt(string $prompt, array $settings): array {
        if ($this->isLocalMode()) {
            // Can't generate mock from raw prompt - return WAIT
            return ['action' => 'WAIT', 'confidence' => 0, 'reasoning' => 'Local mode - configure AI provider.', 'confluences' => []];
        }

        $response = $this->callLLM(
            model:       $this->defaultModel,
            userMessage: $prompt,
            systemPrompt:'You are an expert crypto trading analyst. Always respond with valid JSON only. No markdown. No explanation.',
            maxTokens:   (int) ($settings['analysis_max_tokens'] ?? 1200),
            temperature: (float) ($settings['analysis_temperature'] ?? 0.1),
            keepAlive:   $settings['keep_alive'] ?? '10m'
        );

        try {
            if (preg_match('/\{.*\}/s', $response, $matches)) {
                $cleaned = $matches[0];
            } else {
                $cleaned = $response;
            }
            $cleaned = preg_replace('/(\d),(\d)/', '$1$2', $cleaned);
            $result  = json_decode(trim($cleaned), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $trimmed = trim($cleaned);
                if (substr($trimmed, -1) !== '}') {
                    throw new Exception("The AI response was cut off before finishing (Token Limit Reached). Please increase 'analysis_max_tokens' in settings. Raw output ends with: " . substr($trimmed, -60));
                }
                throw new Exception('Invalid JSON format from AI. The model failed to follow the required JSON structure. Raw output: ' . $response);
            }
            return $result;
        } catch (Exception $e) {
            Log::error('Failed to parse AI recommendation from prompt', ['error' => $e->getMessage(), 'response' => $response ?? '']);
            throw $e;
        }
    }

    public function chat(string $userMessage, string $systemPrompt = '', ?string $model = null): string {
        if ($this->isLocalMode()) {
            return "I am your AI helper running in local mode. Configure a valid OPENROUTER_API_KEY (or keep using Ollama) to enable full responses.";
        }

        $settings = $this->loadSettings();
        $model    = $model ?? $this->chatModel;

        return $this->callLLM(
            model:       $model,
            userMessage: $userMessage,
            systemPrompt:$systemPrompt,
            maxTokens:   (int) ($settings['chat_max_tokens'] ?? 800),
            temperature: (float) ($settings['chat_temperature'] ?? 0.7),
            keepAlive:   $settings['keep_alive'] ?? '10m'
        );
    }

    public function chatWithHistory(array $messages, ?string $model = null, array $tools = []): array|string {
        if ($this->isLocalMode()) {
            $lastMessage = end($messages)['content'] ?? '';
            return "Local mode active. Your message: \"{$lastMessage}\". Configure OPENROUTER_API_KEY to enable full AI responses.";
        }

        $settings = $this->loadSettings();
        $model    = $model ?? $this->chatModel;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float) ($settings['chat_temperature'] ?? 0.7),
            'max_tokens'  => (int) ($settings['chat_max_tokens'] ?? 800),
        ];
        
        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        // keep_alive is Ollama-only
        if ($this->provider === 'ollama') {
            $body['keep_alive'] = $settings['keep_alive'] ?? '10m';
        }

        // Wecai endpoint is /chat; all others use /chat/completions
        $chatEndpoint = $this->provider === 'wecai' ? 'chat' : 'chat/completions';

        $response = Http::withoutVerifying()
            ->withHeaders($this->headers())
            ->timeout(120)
            ->post("{$this->baseUrl}/{$chatEndpoint}", $body);

        if (!$response->successful()) {
            throw new Exception('LLM API error: ' . $response->body());
        }

        $message = $response->json()['choices'][0]['message'] ?? [];
        
        // If there are tool calls, return the raw message object so the caller can process them
        if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
            return $message;
        }

        return $message['content'] ?? '';
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────────────

    private function callLLM(
        string $model,
        string $userMessage,
        string $systemPrompt = '',
        int    $maxTokens    = 400,
        float  $temperature  = 0.0,
        string $keepAlive    = '10m',
        array  $tools        = []
    ): string|array {
        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Build the body — keep_alive is Ollama-only, not supported by Gemini/OpenRouter
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];
        
        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        // Force JSON output for models that support it via API (OpenRouter/Gemini)
        if ($this->provider === 'openrouter' || $this->provider === 'gemini') {
            // Only force JSON object if we are not passing tools, because passing JSON object format with tools sometimes conflicts.
            if (empty($tools)) {
                $body['response_format'] = ['type' => 'json_object'];
            }
        }

        // ⚡ DETERMINISM FIX: Add seed for providers that support it (OpenRouter, Ollama).
        // A fixed seed + temperature=0 guarantees identical outputs for identical inputs.
        // Gemini via native API ignores this field gracefully.
        if ($this->provider === 'openrouter' || $this->provider === 'ollama') {
            $body['seed'] = 42;
        }

        if ($this->provider === 'ollama') {
            $body['keep_alive'] = $keepAlive;
        }

        // Wecai endpoint is /chat; all others use /chat/completions
        $endpoint = $this->provider === 'wecai' ? 'chat' : 'chat/completions';

        $response = Http::withoutVerifying()
            ->withHeaders($this->headers())
            ->timeout(120)
            ->post("{$this->baseUrl}/{$endpoint}", $body);

        if (!$response->successful()) {
            throw new Exception('LLM API error: ' . $response->body());
        }

        $message = $response->json()['choices'][0]['message'] ?? [];
        if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
            return $message;
        }
        return $message['content'] ?? '';
    }

    private function headers(): array {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];
        // OpenRouter needs these extra headers; Gemini, Ollama & Wecai do not
        if ($this->provider === 'openrouter') {
            $headers['HTTP-Referer'] = 'https://aitrading.com';
            $headers['X-Title']      = 'AI Trading Platform';
        }
        return $headers;
    }

    private function isLocalMode(): bool {
        // Ollama and wecai always run full prompts — wecai uses fixed bearer key
        if ($this->provider === 'ollama' || $this->provider === 'wecai') {
            return false;
        }
        // Cloud providers require a real API key
        return !$this->apiKey || in_array(strtolower($this->apiKey), ['', 'local', 'mock', 'placeholder']);
    }

    private function loadSettings(): array {
        $defaults = [
            'ai_provider'          => 'ollama',
            'api_key'              => '',
            'analysis_model'       => env('OPENROUTER_DEFAULT_MODEL', 'qwen2.5:latest'),
            'chat_model'           => env('OPENROUTER_CHAT_MODEL',    'qwen2.5:1.5b'),
            'analysis_max_tokens'  => 400,
            'chat_max_tokens'      => 800,
            // ⚡ DETERMINISM FIX: 0.0 instead of 0.1 — eliminates random TP/SL variance
            // that caused identical market conditions to produce SELL in one run and WAIT in another.
            'analysis_temperature' => 0.0,
            'chat_temperature'     => 0.7,
            'keep_alive'           => '10m',
            'min_confluences'      => 3,
            'min_risk_reward'      => 1.5,
            'multi_timeframe'      => false,
        ];

        if (!file_exists($this->settingsFile)) {
            return $defaults;
        }

        $saved = json_decode(file_get_contents($this->settingsFile), true) ?? [];
        return array_merge($defaults, $saved);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Timeframe constraints
    // ─────────────────────────────────────────────────────────────────

    /**
     * Format a price value adaptively: avoids 0.0000 for very small coins like FLOKI/SHIB.
     * - Large (>= 1000): 2 decimals with commas      e.g. 64,307.84
     * - Normal (>= 1):   4 decimals                  e.g. 1.2345
     * - Small (>= 0.01): 6 decimals                  e.g. 0.169200
     * - Micro (< 0.01):  8 significant decimals      e.g. 0.00002051
     */
    private function fmtPrice(mixed $price): string
    {
        if ($price === null || $price === '') return 'N/A';
        $p = (float) $price;
        if ($p <= 0) return '0';
        if ($p >= 1000)  return number_format($p, 2);
        if ($p >= 1)     return number_format($p, 4);
        if ($p >= 0.01)  return number_format($p, 6);
        // For very small prices, find first significant digit and show 8 sig figs
        $decimals = max(8, (int) ceil(-log10($p)) + 4);
        return rtrim(rtrim(number_format($p, min($decimals, 12)), '0'), '.');
    }

    private function getTimeframeConstraints(string $timeframe): array {
        return match ($timeframe) {
            '1m', '5m'   => ['max_tp3' => 0.02,   'tp_step' => 0.005,  'sl_ratio' => 0.008],
            '15m', '30m' => ['max_tp3' => 0.04,   'tp_step' => 0.01,   'sl_ratio' => 0.015],
            '1h'         => ['max_tp3' => 0.08,   'tp_step' => 0.02,   'sl_ratio' => 0.025],
            '4h'         => ['max_tp3' => 0.15,   'tp_step' => 0.04,   'sl_ratio' => 0.05],
            '1d'         => ['max_tp3' => 0.25,   'tp_step' => 0.08,   'sl_ratio' => 0.08],
            '1w'         => ['max_tp3' => 0.50,   'tp_step' => 0.15,   'sl_ratio' => 0.15],
            default      => ['max_tp3' => 0.15,   'tp_step' => 0.04,   'sl_ratio' => 0.05],
        };
    }

    // ─────────────────────────────────────────────────────────────────
    //  Prompt builder (optimised — ~40% shorter than before)
    // ─────────────────────────────────────────────────────────────────

    public function buildTradingPrompt(array $data, string $symbol, string $timeframe, array $settings): string {
        $classical   = $data['classical']     ?? [];
        $smc         = $data['smc']           ?? [];
        $harmonic    = $data['harmonic']      ?? [];
        $volume      = $data['volume_profile']?? [];
        $price       = $data['current_price'] ?? 0;
        $bias        = $data['overall_bias']  ?? 'neutral';
        $computedConfidence = $data['overall_confidence'] ?? null;
        $computedConfluences = $data['confluences'] ?? [];
        $constraints = $this->getTimeframeConstraints($timeframe);
        $maxTpPct    = round($constraints['max_tp3'] * 100, 1);
        $slPct       = round($constraints['sl_ratio'] * 100, 1);
        $minConf     = (int) ($settings['min_confluences'] ?? 3);
        $minRR       = (float) ($settings['min_risk_reward'] ?? 1.5);

        $rsi      = round($classical['rsi']['current'] ?? 50, 1);
        $rsiCond  = $classical['rsi']['condition'] ?? 'neutral';
        $trend    = $classical['moving_averages']['trend'] ?? 'neutral';
        $macd     = $classical['macd']['trend'] ?? 'neutral';
        // ATR: use adaptive format to avoid 0.0000 for small-price coins
        $atrRaw   = $classical['atr']['current'] ?? null;
        $atr      = $this->fmtPrice($atrRaw);
        $smcBias  = $smc['bias'] ?? 'neutral';

        // Current-price-aware OB formatter: uses correct 'top'/'bottom' keys
        $currentPrice = (float) ($data['current_price'] ?? 0);
        $obWarnings = [];
        $obList = collect($smc['order_blocks'] ?? [])
            ->map(function ($o) use ($currentPrice, &$obWarnings) {
                $type   = strtoupper($o['type'] ?? 'OB');
                $bottom = (float) ($o['bottom'] ?? 0);
                $top    = (float) ($o['top']    ?? 0);
                
                $mid = ($bottom + $top) / 2;
                $dist = $currentPrice > 0 ? abs($mid - $currentPrice) / $currentPrice * 100 : 0;
                $pos = $mid > $currentPrice ? "ABOVE" : "BELOW";

                $label  = "{$type} " . $this->fmtPrice($bottom) . "-" . $this->fmtPrice($top);
                // Detect: is current price INSIDE this OB?
                if ($bottom > 0 && $top > 0 && $currentPrice >= $bottom && $currentPrice <= $top) {
                    $label .= " (PRICE IS INSIDE THIS ZONE ⚠️)";
                    $obWarnings[] = "⚠️ NOTE: Current price is INSIDE a {$type} Order Block (" . $this->fmtPrice($bottom) . "–" . $this->fmtPrice($top) . "). " .
                        ($type === 'BEARISH' ? 'Supply/rejection zone — weigh against other confluences, do not auto-reject a BUY on this alone (real-data check found no significant win-rate difference here).' : 'Price is in demand zone.');
                } else {
                    $label .= " ({" . round($dist, 2) . "}% {$pos} price)";
                }
                return $label;
            })
            ->implode(' | ') ?: 'None';

        $obWarningBlock = !empty($obWarnings) ? "\n⚠️ ORDER BLOCK ALERTS:\n" . implode("\n", $obWarnings) : '';

        $fvgList = collect($smc['fair_value_gaps'] ?? [])
            ->map(function ($f) use ($currentPrice) {
                $type = strtoupper($f['type'] ?? 'FVG');
                $bottom = (float) ($f['bottom'] ?? 0);
                $top = (float) ($f['top'] ?? 0);
                
                $mid = ($bottom + $top) / 2;
                $dist = $currentPrice > 0 ? abs($mid - $currentPrice) / $currentPrice * 100 : 0;
                $pos = $mid > $currentPrice ? "ABOVE" : "BELOW";
                
                $label = "{$type} " . $this->fmtPrice($bottom) . "-" . $this->fmtPrice($top);
                if ($bottom > 0 && $top > 0 && $currentPrice >= $bottom && $currentPrice <= $top) {
                    $label .= " (PRICE IS INSIDE THIS ZONE ⚠️)";
                } else {
                    $label .= " ({" . round($dist, 2) . "}% {$pos} price)";
                }
                return $label;
            })
            ->implode(' | ') ?: 'None';

        $bosList = collect($smc['market_structure']['bos'] ?? array_merge($smc['market_structure']['bos'] ?? [], $smc['market_structure']['choch'] ?? []))
            ->map(fn($b) => ($b['type']??'BOS') . '(' . strtoupper($b['direction']??'BULL') . ') @' . $this->fmtPrice($b['price']??0))
            ->implode(' | ') ?: 'None';

        $fibList = collect($harmonic['fibonacci']['levels'] ?? [])
            ->map(fn($v, $k) => "$k:" . $this->fmtPrice($v))
            ->implode(', ') ?: 'N/A';

        $poc = $volume['key_levels']['poc'] ? $this->fmtPrice($volume['key_levels']['poc']) : 'N/A';
        $vah = $volume['key_levels']['vah'] ? $this->fmtPrice($volume['key_levels']['vah']) : 'N/A';
        $val = $volume['key_levels']['val'] ? $this->fmtPrice($volume['key_levels']['val']) : 'N/A';

        $sup = collect($classical['support_resistance']['support'] ?? [])
            ->pluck('price')->map(fn($p) => $this->fmtPrice($p))->implode(', ');
        $res = collect($classical['support_resistance']['resistance'] ?? [])
            ->pluck('price')->map(fn($p) => $this->fmtPrice($p))->implode(', ');

        $trendingStatus = ($data['is_trending'] ?? false) ? "🔥 CURRENTLY TRENDING" : "Normal";

        $computedConfList = !empty($computedConfluences)
            ? implode(' | ', array_map(fn($c) => is_string($c) ? $c : json_encode($c), $computedConfluences))
            : 'None detected';
        $computedConfLine = $computedConfidence !== null
            ? "Pre-computed technical confidence: {$computedConfidence}% | Detected confluences: {$computedConfList}"
            : '';

        // News block
        $newsData  = $data['specific_news'] ?? [];
        $newsBlock = '';
        if (!empty($newsData)) {
            $newsBlock = "\n📰 LATEST FUNDAMENTAL NEWS (LAST 24H):\n";
            foreach ($newsData as $n) {
                $newsBlock .= "- [" . strtoupper($n['sentiment']) . "] " . $n['title'] . "\n";
            }
        }

        // Pre-Trade Filter block – surface warnings and adjusted confidence to the AI
        $filterData   = $data['pre_trade_filter'] ?? null;
        $filterBlock  = '';
        if ($filterData) {
            $adjConf  = $filterData['adjusted_confidence'] ?? $computedConfidence;
            $verdict  = $filterData['pre_trade_verdict']  ?? 'PROCEED';
            $filterWarnings = $filterData['warnings'] ?? [];
            $filterBlock  = "\n🔍 PRE-TRADE QUALITY FILTER:\n";
            $filterBlock .= "Verdict: {$verdict} | Adjusted Confidence: {$adjConf}%\n";
            if (!empty($filterWarnings)) {
                foreach ($filterWarnings as $w) {
                    $filterBlock .= "  {$w}\n";
                }
            }
            if ($verdict === 'WAIT') {
                $filterBlock .= "⚠️ The pre-trade filter signals WAIT. Only override with STRONG multi-school confluence.\n";
            }
        }

        // Session context block
        $sessionData  = $data['session_context'] ?? null;
        $sessionBlock = '';
        if ($sessionData) {
            $sessions      = implode(' + ', $sessionData['active_sessions'] ?? ['Unknown']);
            $sessionFilter = $sessionData['trade_filter'] ?? 'PROCEED';
            $sessionAlert  = $sessionData['session_alert'] ?? '';
            $sessionBlock  = "\n⏰ MARKET SESSION: {$sessions} | {$sessionData['utc_time']} | Filter: {$sessionFilter}\n";
            if ($sessionAlert) {
                $sessionBlock .= "  {$sessionAlert}\n";
            }
            if ($sessionFilter === 'AVOID') {
                $sessionBlock .= "⚠️ LOW LIQUIDITY: Avoid BUY/SELL — prefer WAIT. Fakeouts are common now.\n";
            } elseif ($sessionFilter === 'CAUTION') {
                $sessionBlock .= "⚠️ HIGH CAUTION: Wait for the first 30-60 minutes of this session to pass before entering.\n";
            } elseif ($sessionFilter === 'OPTIMAL') {
                $sessionBlock .= "✅ OPTIMAL CONDITIONS: London–NY overlap. High liquidity. Breakouts are valid.\n";
            }
        }

        // AMD Cycle block (Accumulation-Manipulation-Distribution)
        $amdData  = $data['amd_analysis'] ?? null;
        $amdBlock = '';
        if ($amdData && !empty($amdData['manipulation_detected'])) {
            $manip = $amdData['manipulation'];
            $amdBlock = "\n🎯 AMD CYCLE: " . strtoupper($amdData['expected_direction']) . " distribution expected "
                      . "(accumulation \${$amdData['accumulation']['low']}-\${$amdData['accumulation']['high']}, "
                      . strtoupper($manip['direction']) . " sweep at {$manip['time']} {$manip['session']} open)\n"
                      . "  Treat this as an additional confirmation signal only — do not treat it as a core requirement.\n";
        }

        // ATR SL guidance line
        $atrRaw   = $data['classical']['atr']['current'] ?? null;
        $atrBlock = '';
        $sl15x    = 'N/A'; // fallback if ATR unavailable
        if ($atrRaw && ($data['current_price'] ?? 0) > 0) {
            $atrPct   = round(($atrRaw / $data['current_price']) * 100, 3);
            $sl15x    = round($atrRaw * 1.5, 8);
            $sl2x     = round($atrRaw * 2.0, 8);
            $atrBlock = "\nATR SL Guide: ATR={$atr} ({$atrPct}% of price). Recommended SL = Entry ± 1.5×ATR (\${$sl15x}). Volatility SL = Entry ± 2×ATR (\${$sl2x}).\n";
        }

        return <<<PROMPT
Crypto: {$symbol} | TF: {$timeframe} | Price: {$this->fmtPrice($data['current_price'] ?? 0)} | Bias: {$bias} | Status: {$trendingStatus}
{$obWarningBlock}
SMC ({$smcBias}): OB={$obList} | FVG={$fvgList} | Structure={$bosList}
Fibonacci: {$fibList}
Volume: POC={$poc} VAH={$vah} VAL={$val}
Classical: MA={$trend} RSI={$rsi}({$rsiCond}) MACD={$macd} ATR={$atr}
S/R: Support=[{$sup}] Resistance=[{$res}]
{$computedConfLine}{$newsBlock}{$filterBlock}{$sessionBlock}{$amdBlock}{$atrBlock}
RULES:
- If market is 'squeezed' or 'ranging' (strong resistance above and support below), DO NOT default to WAIT. Look for high-probability SCALPING / COUNTER-TREND trades (e.g. shorting resistance, buying support) if the Risk/Reward is at least {$minRR}.

- BUY/SELL only if >= {$minConf} confluences from DIFFERENT schools.
- R:R >= 1:{$minRR} (use TP2 for reward calc).
- entry_price within 0.5% of current price.
- Price inside a BEARISH OB zone is a caution flag, not an automatic veto — a real-data backtest (747 bullish signals) found no significant win-rate difference vs. being outside one (94.7% vs 96.7%). Weigh it with other confluences instead of auto-rejecting the BUY. If price is directly below a BLOCKING Bearish FVG, you MUST still output WAIT or SELL — never BUY (not separately tested, still a hard rule).
- ⚠️ VOLUME & FAKE PUMP CHECK: If you detect a sudden pump/dump but volume is poor or Volume Profile status indicates weakness/fake pump, you MUST reject the trade and output WAIT to avoid liquidity sweeps.
- Place SL behind nearest invalidation structure (OB/Swing High/Low) + ATR buffer. Max SL: {$slPct}%.
- ⚠️ CRITICAL SL RULE: The SL distance from entry MUST be >= 1.5× ATR. Current ATR = {$atr}. Minimum SL distance = {$sl15x}. NEVER suggest an SL closer than this — a tighter SL will be hit by normal market noise before the trade plays out, regardless of the signal quality.
- ⚠️ PRICE DISCOVERY SL RULE: If the coin is in Price Discovery (breaking all-time highs with no resistance above) and 1h support is too far or weak, DO NOT rely purely on fragile 1h FVGs for Stop Loss. Use a wider ATR multiplier (e.g., 2x ATR) to avoid getting stopped out by fakeout wicks.
- TP1/TP2/TP3 aligned with FVG, POC/VAH/VAL, or S/R levels. Max TP3 distance: {$maxTpPct}%.
- Your "confidence" must be grounded in the pre-computed technical confidence above.
- ⚠️ When writing reasoning, clearly distinguish between individual indicator trends and overall bias (e.g. say 'despite an uptrend in MA, the overall bias remains bearish' instead of 'uptrend with a bearish bias').

Respond ONLY with this JSON (no markdown):
{"action":"BUY|SELL|WAIT","entry_price":0.0,"tp1":0.0,"tp2":0.0,"tp3":0.0,"sl":0.0,"risk_reward":"1:X","confidence":0,"reasoning":"...","confluences":["..."],"invalidation":"..."}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Multi-Timeframe Prompt Builder
    // ─────────────────────────────────────────────────────────────────

    public function buildMtfTradingPrompt(
        array  $htfData,   string $htfTf,
        array  $mtfData,   string $mtfTf,
        array  $ltfData,   string $ltfTf,
        string $symbol,
        array  $settings
    ): string {
        $price    = $ltfData['current_price'] ?? 0;
        $minConf  = (int)   ($settings['min_confluences'] ?? 3);
        $minRR    = (float) ($settings['min_risk_reward'] ?? 1.5);
        $constraints = $this->getTimeframeConstraints($ltfTf);
        $maxTpPct    = round($constraints['max_tp3'] * 100, 1);
        $slPct       = round($constraints['sl_ratio'] * 100, 1);

        $fmt = function (array $data, string $tf): string {
            $cl  = $data['classical'] ?? [];
            $smc = $data['smc']       ?? [];
            $bias   = strtoupper($data['overall_bias'] ?? 'NEUTRAL');
            $conf   = $data['overall_confidence'] ?? 0;
            $trend  = $cl['moving_averages']['trend'] ?? 'neutral';
            $rsi    = round($cl['rsi']['current'] ?? 50, 1);
            $macd   = $cl['macd']['trend'] ?? 'neutral';
            $atr    = $this->fmtPrice($cl['atr']['current'] ?? null);
            $smcB   = strtoupper($smc['bias'] ?? 'NEUTRAL');

            // OBs with inside-zone detection, using correct 'top'/'bottom' keys
            $priceVal = (float) ($data['current_price'] ?? 0);
            $obs = collect($smc['order_blocks'] ?? [])
                ->map(function ($o) use ($priceVal) {
                    $type   = strtoupper($o['type'] ?? 'OB');
                    $bottom = (float) ($o['bottom'] ?? 0);
                    $top    = (float) ($o['top']    ?? 0);
                    $label  = "{$type} " . $this->fmtPrice($bottom) . "-" . $this->fmtPrice($top);
                    if ($bottom > 0 && $top > 0 && $priceVal >= $bottom && $priceVal <= $top) {
                        $label .= "[⚠️PRICE_INSIDE_" . $type . "_OB]";
                    }
                    return $label;
                })
                ->implode(' | ') ?: 'None';

            $fvgs = collect($smc['fair_value_gaps'] ?? [])
                ->map(fn($f) => strtoupper($f['type']??'FVG').' '.$this->fmtPrice($f['bottom']??0).'-'.$this->fmtPrice($f['top']??0))
                ->implode(' | ') ?: 'None';

            $bos = collect(array_merge($smc['market_structure']['bos'] ?? [], $smc['market_structure']['choch'] ?? []))
                ->map(fn($b) => ($b['type']??'BOS').'('.strtoupper($b['direction']??'BULL').') @'.$this->fmtPrice($b['price']??0))
                ->implode(' | ') ?: 'None';

            $sups = collect($cl['support_resistance']['support'] ?? [])
                ->pluck('price')->map(fn($p) => $this->fmtPrice($p))->implode(', ') ?: 'N/A';
            $ress = collect($cl['support_resistance']['resistance'] ?? [])
                ->pluck('price')->map(fn($p) => $this->fmtPrice($p))->implode(', ') ?: 'N/A';

            $detectedConfs = collect($data['confluences'] ?? [])
                ->map(fn($c) => is_string($c) ? $c : json_encode($c))
                ->implode(' | ') ?: 'None';

            return "[{$tf}] Bias={$bias}({$conf}%) MA={$trend} RSI={$rsi} MACD={$macd} ATR={$atr} | SMC={$smcB} OB={$obs} FVG={$fvgs} Structure={$bos} | S=[{$sups}] R=[{$ress}] | Confluences: {$detectedConfs}";
        };

        $htfLine = $fmt($htfData, $htfTf);
        $mtfLine = $fmt($mtfData, $mtfTf);
        $ltfLine = $fmt($ltfData, $ltfTf);

        $htfBias = strtoupper($htfData['overall_bias'] ?? 'NEUTRAL');
        $mtfBias = strtoupper($mtfData['overall_bias'] ?? 'NEUTRAL');
        $ltfBias = strtoupper($ltfData['overall_bias'] ?? 'NEUTRAL');

        $biasAlignment = ($htfBias === $mtfBias && $mtfBias === $ltfBias)
            ? "✅ ALL 3 TIMEFRAMES ALIGNED: {$htfBias}"
            : "⚠️ MIXED BIAS: HTF={$htfBias} MTF={$mtfBias} LTF={$ltfBias}";

        $newsData = $ltfData['specific_news'] ?? [];
        $newsBlock = '';
        if (!empty($newsData)) {
            $newsBlock = "\n📰 LATEST FUNDAMENTAL NEWS (LAST 24H):\n";
            foreach ($newsData as $n) {
                $newsBlock .= "- [" . strtoupper($n['sentiment']) . "] " . $n['title'] . "\n";
            }
        }

        return <<<PROMPT
Multi-Timeframe Analysis for {$symbol} | Entry TF: {$ltfTf} | Price: \${$price}
Bias Alignment: {$biasAlignment}

TOP-DOWN DATA:
{$htfLine}
{$mtfLine}
{$ltfLine}
{$newsBlock}
RULES:
- Use HTF [{$htfTf}] for bias direction only.
- Use MTF [{$mtfTf}] to identify OB/FVG confluence zones.
- Use LTF [{$ltfTf}] for precise entry (CHoCH confirmation).
- BUY/SELL only if ALL 3 timeframes share the same bias direction AND >= {$minConf} confluences across timeframes.
- If biases conflict, output WAIT.
- entry_price within 0.5% of {$price}.
- If market is 'squeezed' or 'ranging' (strong resistance above and support below), DO NOT default to WAIT. Look for high-probability SCALPING / COUNTER-TREND trades (e.g. shorting resistance, buying support) if the Risk/Reward is at least {$minRR}.
- Price inside a Bearish OB is a caution flag, not an automatic veto — a real-data backtest found no significant win-rate difference vs. being outside one; weigh it alongside other confluences instead of auto-rejecting the BUY. NEVER issue a BUY if price is directly underneath a BLOCKING Bearish FVG (not separately tested, still a hard rule).
- Minimum required confluences: {$minConf}.
- Minimum risk-reward ratio: {$minRR}.
- Max TP distance: {$maxTpPct}%.
- Max SL distance: {$slPct}%.
- Ensure TP values are logically ordered. (TP1 < TP2 < TP3 for BUY, opposite for SELL).
- Ensure SL is beyond local structure (use ATR and OB/FVG buffers).
- ⚠️ When writing reasoning, clearly distinguish between individual indicator trends and overall bias (e.g. say 'despite an uptrend in MA, the overall bias remains bearish' instead of 'uptrend with a bearish bias').
- Use strict JSON format ONLY. No markdown, no explanations outside the JSON object.
{"action":"BUY|SELL|WAIT","entry_price":0.0,"tp1":0.0,"tp2":0.0,"tp3":0.0,"sl":0.0,"risk_reward":"1:X","confidence":0,"reasoning":"...","confluences":["..."],"invalidation":"..."}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Mock recommendation (used when OPENROUTER_API_KEY = 'ollama')
    // ─────────────────────────────────────────────────────────────────

    private function generateMockRecommendation(array $data, string $symbol, string $timeframe): array {
        $currentPrice = $data['current_price'] ?? 65000.0;
        $overallBias  = $data['overall_bias']  ?? 'neutral';
        $constraints  = $this->getTimeframeConstraints($timeframe);
        $settings     = $this->loadSettings();
        $minConf      = (int) ($settings['min_confluences'] ?? 3);
        $minRR        = (float) ($settings['min_risk_reward'] ?? 1.5);

        $supports    = collect($data['classical']['support_resistance']['support']    ?? [])->pluck('price')->map(fn($p) => (float)$p)->sort()->values()->toArray();
        $resistances = collect($data['classical']['support_resistance']['resistance'] ?? [])->pluck('price')->map(fn($p) => (float)$p)->sort()->values()->toArray();
        $orderBlocks = $data['smc']['order_blocks']   ?? [];
        $fvgs        = $data['smc']['fair_value_gaps'] ?? [];
        $bosChoch    = $data['smc']['bos_choch']      ?? [];
        $fibLevels   = $data['harmonic']['fibonacci']['levels'] ?? [];

        $confluences = [];
        if (!empty($orderBlocks)) {
            $ob = end($orderBlocks);
            $confluences[] = "Order Block: " . strtoupper($ob['type'] ?? 'bullish') . " Zone ~$" . number_format($ob['price_min'] ?? $currentPrice, 2);
        }
        if (!empty($fvgs)) {
            $fvg = end($fvgs);
            $confluences[] = "FVG: " . strtoupper($fvg['type'] ?? 'bullish') . " Gap ~$" . number_format($fvg['bottom'] ?? $currentPrice, 2);
        }
        if (!empty($bosChoch)) {
            $bos = end($bosChoch);
            $confluences[] = "Structure: " . ($bos['type'] ?? 'BOS') . " (" . ($bos['direction'] ?? 'bullish') . ")";
        }
        if (!empty($fibLevels['0.618'])) {
            $confluences[] = "Fib 0.618 Golden Pocket at $" . number_format($fibLevels['0.618'], 2);
        }
        if (isset($data['classical']['rsi']['condition']) && $data['classical']['rsi']['condition'] !== 'neutral') {
            $confluences[] = "RSI " . ucfirst($data['classical']['rsi']['condition']) . " (" . round($data['classical']['rsi']['current'] ?? 50, 1) . ")";
        }
        if (isset($data['classical']['macd']['trend']) && $data['classical']['macd']['trend'] !== 'neutral') {
            $confluences[] = "MACD " . ucfirst($data['classical']['macd']['trend']) . " Crossover";
        }
        if (!empty($data['is_trending'])) {
            $confluences[] = "🔥 Coin is currently TRENDING!";
        }

        $action = 'WAIT';
        if (count($confluences) >= $minConf) {
            $action = strtoupper($overallBias) === 'BULLISH' ? 'BUY' : 'SELL';
        }

        $tp1 = null; $tp2 = null; $tp3 = null; $sl = null;

        if ($action === 'BUY') {
            $obBelow      = collect($orderBlocks)->filter(fn($ob) => ($ob['price_min'] ?? 0) < $currentPrice)->sortByDesc('price_min')->first();
            $supportBelow = collect($supports)->filter(fn($p) => $p < $currentPrice)->sortByDesc(fn($p) => $p)->first();
            $sl  = $obBelow['price_min'] ?? ($supportBelow ?: $currentPrice * (1 - $constraints['sl_ratio']));
            $sl  = max($sl, $currentPrice * (1 - $constraints['max_tp3']));
            $resAbove = collect($resistances)->filter(fn($p) => $p > $currentPrice)->sort()->values()->toArray();
            $tp1 = $resAbove[0] ?? $currentPrice * (1 + $constraints['tp_step']);
            $tp2 = $resAbove[1] ?? $currentPrice * (1 + $constraints['tp_step'] * 2);
            $tp3 = $resAbove[2] ?? $currentPrice * (1 + $constraints['tp_step'] * 3);
            $maxTp   = $currentPrice * (1 + $constraints['max_tp3']);
            $minStep = $currentPrice * $constraints['tp_step'] * 0.5;
            $tp1 = min($tp1, $maxTp); $tp2 = min($tp2, $maxTp); $tp3 = min($tp3, $maxTp);
            if ($tp2 <= $tp1 + $minStep) $tp2 = $tp1 + $minStep;
            if ($tp3 <= $tp2 + $minStep) $tp3 = $tp2 + $minStep;

        } elseif ($action === 'SELL') {
            $obAbove = collect($orderBlocks)->filter(fn($ob) => ($ob['price_max'] ?? INF) > $currentPrice)->sortBy('price_max')->first();
            $resAbove = collect($resistances)->filter(fn($p) => $p > $currentPrice)->sort()->first();
            $sl  = $obAbove['price_max'] ?? ($resAbove ?: $currentPrice * (1 + $constraints['sl_ratio']));
            $sl  = min($sl, $currentPrice * (1 + $constraints['max_tp3']));
            $supBelow = collect($supports)->filter(fn($p) => $p < $currentPrice)->sortByDesc(fn($p) => $p)->values()->toArray();
            $tp1 = $supBelow[0] ?? $currentPrice * (1 - $constraints['tp_step']);
            $tp2 = $supBelow[1] ?? $currentPrice * (1 - $constraints['tp_step'] * 2);
            $tp3 = $supBelow[2] ?? $currentPrice * (1 - $constraints['tp_step'] * 3);
            $minTp   = $currentPrice * (1 - $constraints['max_tp3']);
            $minStep = $currentPrice * $constraints['tp_step'] * 0.5;
            $tp1 = max($tp1, $minTp); $tp2 = max($tp2, $minTp); $tp3 = max($tp3, $minTp);
            if ($tp2 >= $tp1 - $minStep) $tp2 = $tp1 - $minStep;
            if ($tp3 >= $tp2 - $minStep) $tp3 = $tp2 - $minStep;
        }

        $rr = 'N/A';
        if ($action !== 'WAIT' && $sl !== null && $tp2 !== null) {
            $risk    = abs($currentPrice - $sl);
            $reward  = abs($tp2 - $currentPrice);
            $rrValue = $risk > 0 ? round($reward / $risk, 2) : 0;
            $rr      = "1:{$rrValue}";
        }

        return [
            'action'       => $action,
            'entry_price'  => $currentPrice,
            'tp1'          => $tp1 ? round($tp1, 8) : null,
            'tp2'          => $tp2 ? round($tp2, 8) : null,
            'tp3'          => $tp3 ? round($tp3, 8) : null,
            'sl'           => $sl  ? round($sl,  8) : null,
            'risk_reward'  => $rr,
            'confidence'   => $action !== 'WAIT' ? rand(78, 92) : 0,
            'reasoning'    => $action !== 'WAIT'
                ? "Multi-School Analysis for {$symbol} ({$timeframe}). {$overallBias} direction confirmed by " . count($confluences) . " confluences across SMC (OB/FVG), Fibonacci, Volume Profile, and Classical Indicators."
                : "Insufficient confluences (< {$minConf}) across analysis schools. Recommending WAIT.",
            'confluences'  => $confluences,
            'invalidation' => $action === 'BUY'
                ? "Candle close below Order Block / Support at $" . number_format((float)$sl, 2)
                : "Candle close above Order Block / Resistance at $" . number_format((float)$sl, 2),
        ];
    }
}
