<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class SettingsController extends Controller
{
    private string $settingsFile;

    public function __construct()
    {
        $this->settingsFile = storage_path('app/ai_settings.json');
    }

    /**
     * Get all AI settings + available Ollama models.
     */
    public function index(): JsonResponse
    {
        $settings = $this->loadSettings();
        $models   = $this->fetchModels();

        // Decrypt keys to send to frontend
        foreach (['gemini_api_key', 'openrouter_api_key', 'wecai_api_key'] as $keyName) {
            if (!empty($settings[$keyName])) {
                try {
                    $settings[$keyName] = Crypt::decryptString($settings[$keyName]);
                } catch (DecryptException $e) {
                    // Return as is if it wasn't encrypted
                }
            }
        }

        return response()->json([
            'settings'         => $settings,
            'available_models' => $models,
        ]);
    }

    /**
     * Update AI settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ai_provider'         => 'sometimes|string|in:ollama,gemini,openrouter,wecai,antigravity',
            'gemini_api_key'      => 'present|nullable|string',
            'openrouter_api_key'  => 'present|nullable|string',
            'wecai_api_key'       => 'present|nullable|string',
            // Antigravity bridge settings
            'agent_bridge_folder'        => 'sometimes|nullable|string',
            'agent_bridge_timeout'       => 'sometimes|integer|min:60|max:600',
            'agent_bridge_poll_interval' => 'sometimes|integer|min:3|max:30',
            'analysis_model'      => 'sometimes|string',
            'chat_model'          => 'sometimes|string',
            'news_model'          => 'sometimes|string',
            'analysis_max_tokens'  => 'sometimes|integer|min:100|max:4000',
            'chat_max_tokens'      => 'sometimes|integer|min:100|max:4000',
            'analysis_temperature' => 'sometimes|numeric|min:0|max:1',
            'chat_temperature'     => 'sometimes|numeric|min:0|max:1',
            'keep_alive'           => 'sometimes|string',
            'min_confluences'      => 'sometimes|integer|min:1|max:5',
            'min_risk_reward'      => 'sometimes|numeric|min:1|max:5',
            'pre_trade_min_confidence' => 'sometimes|integer|min:50|max:90',
            'skip_ai_on_filter_wait' => 'sometimes|boolean',
            'ai_decision_mode'     => 'sometimes|string|in:ai,deterministic',
            // ── Optional Analysis Modules — each independently skippable ────────
            'enable_whale_tracking'  => 'sometimes|boolean',
            'enable_market_cycles'   => 'sometimes|boolean',
            'enable_news_sentiment'  => 'sometimes|boolean',
            'enable_harmonic_patterns' => 'sometimes|boolean',
            'enable_classical_patterns' => 'sometimes|boolean',
            'enable_candle_patterns' => 'sometimes|boolean',
            'multi_timeframe'      => 'sometimes|boolean',
            // ── Sessions & Costs Filter Settings ───────────────────────────────────
            'sessions_filter_enabled'    => 'sometimes|boolean',
            'sessions_allowed'           => 'sometimes|nullable|array',
            'sessions_allowed.*'         => 'string|in:Asian,London,New York,Off-hours',
            'costs_filter_enabled'       => 'sometimes|boolean',
            'costs_taker_fee_bps'        => 'sometimes|numeric|min:0|max:100',
            'costs_slippage_bps'         => 'sometimes|numeric|min:0|max:100',
            'costs_min_net_edge_bps'     => 'sometimes|numeric|min:0|max:500',
            // ── Execution-Quality Engine Settings ────────────────────────────────
            'order_book_enabled'         => 'sometimes|boolean',
            'cvd_enabled'                => 'sometimes|boolean',
            'cvd_lookback'               => 'sometimes|integer|min:5|max:100',
            'taker_pressure_enabled'     => 'sometimes|boolean',
            'taker_pressure_lookback'    => 'sometimes|integer|min:5|max:100',
            // ── MTF Timeframe Settings ──────────────────────────────────────
            'mtf_htf'              => 'sometimes|nullable|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,1w',
            'mtf_mtf'              => 'sometimes|nullable|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,1w',
            'mtf_ltf'              => 'sometimes|nullable|string|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,1w',
            'scan_interval'        => 'sometimes|string|in:10m,15m,20m,30m,45m,1h,2h,4h,8h,12h,24h',
            'scan_timeframe'       => 'sometimes|string|in:1m,5m,15m,30m,1h,4h,1d,1w',
            // ── Scanner Strategy Settings ──────────────────────────────────
            'strategy_trending_enabled'       => 'sometimes|boolean',
            'strategy_trending_limit'         => 'sometimes|integer|min:1|max:500',
            'strategy_volume_enabled'         => 'sometimes|boolean',
            'strategy_volume_limit'           => 'sometimes|integer|min:1|max:500',
            'strategy_early_enabled'          => 'sometimes|boolean',
            'strategy_early_limit'            => 'sometimes|integer|min:1|max:500',
            'strategy_reversal_enabled'       => 'sometimes|boolean',
            'strategy_reversal_limit'         => 'sometimes|integer|min:1|max:500',
            // ── Scanner Strategy Threshold Settings ────────────────────────
            'strategy_min_volume_usdt'        => 'sometimes|numeric|min:100000',
            'strategy_volume_min_change'      => 'sometimes|numeric|min:0|max:100',
            'strategy_volume_max_change'      => 'sometimes|numeric|min:0|max:100',
            'strategy_early_min_change'       => 'sometimes|numeric|min:0|max:100',
            'strategy_early_max_change'       => 'sometimes|numeric|min:0|max:100',
            'strategy_reversal_min_dump'      => 'sometimes|numeric|min:-100|max:0',
            'strategy_reversal_min_bounce'    => 'sometimes|numeric|min:0|max:50',
            'strategy_volume_window'          => 'sometimes|string|in:1h,4h,12h,24h',
        ]);

        $settings = $this->loadSettings();

        // Encrypt the API keys before merging/saving (only if a non-empty key was submitted)
        foreach (['gemini_api_key', 'openrouter_api_key', 'wecai_api_key'] as $keyName) {
            if (isset($validated[$keyName])) {
                if (!empty($validated[$keyName])) {
                    $validated[$keyName] = Crypt::encryptString($validated[$keyName]);
                } else {
                    // If user sent empty string, preserve the existing encrypted key (don't wipe it)
                    $validated[$keyName] = $settings[$keyName] ?? '';
                }
            }
        }

        $settings = array_merge($settings, $validated);
        $this->saveSettings($settings);

        Log::info('AI Settings updated', array_diff_key($validated, ['gemini_api_key' => '', 'openrouter_api_key' => '', 'wecai_api_key' => '']));

        $returnSettings = $settings;
        foreach (['gemini_api_key', 'openrouter_api_key', 'wecai_api_key'] as $keyName) {
            if (!empty($returnSettings[$keyName])) {
                try {
                    $returnSettings[$keyName] = Crypt::decryptString($returnSettings[$keyName]);
                } catch (DecryptException $e) {
                    // leave as is
                }
            }
        }

        return response()->json([
            'message'     => 'Settings updated successfully',
            'settings'    => $returnSettings,
        ]);
    }

    /**
     * Always fetch Ollama models (regardless of saved ai_provider).
     * Used by the frontend when switching back to Ollama mode.
     */
    public function ollamaModels(): JsonResponse
    {
        try {
            $ollamaBase = env('OPENROUTER_BASE_URL', 'http://localhost:11434/v1');
            $base = preg_replace('#/v1$#', '', rtrim($ollamaBase, '/'));

            $response = Http::withoutVerifying()->timeout(5)->get("{$base}/api/tags");

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->map(fn($m) => [
                        'name'    => $m['name'],
                        'size_gb' => round(($m['size'] ?? 0) / 1e9, 1),
                    ])->values()->toArray();

                return response()->json(['models' => $models ?: $this->defaultModels()]);
            }
        } catch (\Exception $e) {
            Log::warning('Could not fetch Ollama models: ' . $e->getMessage());
        }

        return response()->json(['models' => $this->defaultModels()]);
    }

    /**
     * Fetch available models based on selected provider.
     */
    private function fetchModels(): array
    {
        $settings = $this->loadSettings();
        $provider = $settings['ai_provider'] ?? 'ollama';

        if ($provider === 'antigravity') {
            return [
                ['name' => 'Antigravity Agent', 'size_gb' => 0],
            ];
        }

        if ($provider === 'gemini') {
            return [
                ['name' => 'gemini-2.5-flash-preview-05-20', 'size_gb' => 0],
                ['name' => 'gemini-2.0-flash',               'size_gb' => 0],
                ['name' => 'gemini-2.0-flash-lite',          'size_gb' => 0],
                ['name' => 'gemini-1.5-pro',                 'size_gb' => 0],
                ['name' => 'gemini-1.5-flash',               'size_gb' => 0],
            ];
        }

        if ($provider === 'openrouter') {
            return [
                ['name' => 'openrouter/free',    'size_gb' => 0],
                ['name' => 'google/gemma-4-31b-it:free', 'size_gb' => 0],
                ['name' => 'google/gemini-2.5-flash',            'size_gb' => 0],
                ['name' => 'google/gemini-2.0-flash-001',        'size_gb' => 0],
                ['name' => 'openai/gpt-4o-mini',                 'size_gb' => 0],
                ['name' => 'anthropic/claude-3-5-haiku',         'size_gb' => 0],
                ['name' => 'meta-llama/llama-3.3-70b-instruct', 'size_gb' => 0],
            ];
        }

        if ($provider === 'wecai') {
            return [
                ['name' => 'qwen2.5:3b',  'size_gb' => 0],
                ['name' => 'qwen2.5:7b',  'size_gb' => 0],
                ['name' => 'llama3',       'size_gb' => 0],
            ];
        }

        try {
            $ollamaBase = env('OPENROUTER_BASE_URL', 'http://localhost:11434/v1');
            $base = preg_replace('#/v1$#', '', rtrim($ollamaBase, '/'));

            $response = Http::withoutVerifying()->timeout(5)->get("{$base}/api/tags");

            if ($response->successful()) {
                $models = collect($response->json('models', []))
                    ->map(fn($m) => [
                        'name'    => $m['name'],
                        'size_gb' => round(($m['size'] ?? 0) / 1e9, 1),
                        'modified_at' => $m['modified_at'] ?? null,
                    ])->values()->toArray();

                return $models ?: $this->defaultModels();
            }
        } catch (\Exception $e) {
            Log::warning('Could not fetch Ollama models: ' . $e->getMessage());
        }

        return $this->defaultModels();
    }

    private function defaultModels(): array
    {
        return [
            ['name' => 'qwen2.5:1.5b',  'size_gb' => 1.0,  'modified_at' => null],
            ['name' => 'qwen2.5:latest', 'size_gb' => 4.7,  'modified_at' => null],
        ];
    }

    /**
     * Public accessor so other controllers (e.g. AgentBridgeController) can read settings.
     */
    public function getSettings(): array
    {
        return $this->loadSettings();
    }

    private function loadSettings(): array
    {
        $defaults = [
            'ai_provider'                => 'ollama',
            'api_key'                    => '',
            'analysis_model'             => env('OPENROUTER_DEFAULT_MODEL', 'qwen2.5:latest'),
            'chat_model'                 => env('OPENROUTER_CHAT_MODEL',    'qwen2.5:1.5b'),
            'news_model'                 => 'qwen2.5:latest',
            'analysis_max_tokens'        => 400,
            'chat_max_tokens'            => 800,
            'analysis_temperature'       => 0.1,
            'chat_temperature'           => 0.7,
            'keep_alive'                 => '10m',
            'min_confluences'            => 3,
            // Raised 1.5 → 1.8 → 2.0 across two rounds of real-report review: a 1.51
            // R:R was passing the hard gate while Trade Quality's own R:R component
            // simultaneously labeled it "Below Target" (that tier starts at 2.0) —
            // the gate and the quality assessment disagreed about the same trade.
            // 2.0 makes the hard gate match the quality tier it's judged against.
            // Existing users who've already set this in Settings keep their own
            // value; this only changes the fallback for unset installs.
            'min_risk_reward'            => 2.0,
            'pre_trade_min_confidence'   => 65,
            'skip_ai_on_filter_wait'     => false,
            'ai_decision_mode'           => 'ai',
            'enable_whale_tracking'      => true,
            'enable_market_cycles'       => true,
            'enable_news_sentiment'      => true,
            'enable_harmonic_patterns'   => true,
            'multi_timeframe'            => false,
            'mtf_htf'                    => '1d',
            'mtf_mtf'                    => '4h',
            'mtf_ltf'                    => '1h',
            'scan_interval'              => '4h',
            'scan_timeframe'             => '4h',
            // Scanner Strategy Settings defaults
            'strategy_trending_enabled'      => true,
            'strategy_trending_limit'        => 20,
            'strategy_volume_enabled'        => true,
            'strategy_volume_limit'          => 10,
            'strategy_early_enabled'         => true,
            'strategy_early_limit'           => 10,
            'strategy_reversal_enabled'      => true,
            'strategy_reversal_limit'        => 10,
            // Scanner Strategy Threshold defaults
            'strategy_min_volume_usdt'       => 2000000,
            'strategy_volume_min_change'     => 1.0,
            'strategy_volume_max_change'     => 5.0,
            'strategy_early_min_change'      => 5.0,
            'strategy_early_max_change'      => 10.0,
            'strategy_reversal_min_dump'     => -10.0,
            'strategy_reversal_min_bounce'   => 2.0,
            'strategy_volume_window'         => '24h',
            // Sessions & Costs Filter defaults
            'sessions_filter_enabled'    => false,
            'sessions_allowed'           => ['London', 'New York'],
            'costs_filter_enabled'       => false,
            'costs_taker_fee_bps'        => 5.0,
            'costs_slippage_bps'         => 3.0,
            'costs_min_net_edge_bps'     => 5.0,
            // Execution-Quality Engine defaults
            'order_book_enabled'         => false,
            'cvd_enabled'                => false,
            'cvd_lookback'               => 20,
            'taker_pressure_enabled'     => false,
            'taker_pressure_lookback'    => 20,
            // Antigravity bridge defaults
            'agent_bridge_folder'        => '',
            'agent_bridge_timeout'       => 300,
            'agent_bridge_poll_interval' => 5,
        ];

        if (!file_exists($this->settingsFile)) {
            return $defaults;
        }

        $saved = json_decode(file_get_contents($this->settingsFile), true) ?? [];
        return array_merge($defaults, $saved);
    }

    private function saveSettings(array $settings): void
    {
        file_put_contents(
            $this->settingsFile,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Halt all AI operations (clear queues)
     */
    public function halt(): JsonResponse
    {
        try {
            // Signal a currently-running scan loop to stop after its current coin —
            // truncating the queue alone only removes jobs that haven't started yet.
            \Illuminate\Support\Facades\Cache::put('stop_ai_scan', true, now()->addMinutes(15));
            \Illuminate\Support\Facades\DB::table('jobs')->truncate();
            Log::info('AI operations halted by user request. Job queue cleared.');
            return response()->json(['message' => 'All AI scans and operations stopped successfully.']);
        } catch (\Exception $e) {
            Log::error('Failed to halt AI operations: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to halt AI operations.'], 500);
        }
    }

    public function resetData(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
            
            \App\Models\Analysis::truncate();
            \App\Models\Recommendation::truncate();
            \App\Models\PaperTrade::truncate();
            \App\Models\PaperTradingSession::truncate();
            \App\Models\Notification::truncate();
            \App\Models\ChatConversation::truncate();
            \App\Models\ChatMessage::truncate();
            
            // Delete Agent Bridge files
            $bridgePath = storage_path('app/agent_bridge');
            if (is_dir($bridgePath)) {
                foreach (['pending', 'processing', 'completed', 'failed'] as $dir) {
                    $dirPath = $bridgePath . '/' . $dir;
                    if (is_dir($dirPath)) {
                        array_map('unlink', glob("$dirPath/*.*"));
                    }
                }
            }

            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

            return response()->json(['message' => 'All tracking, trades, and opportunities have been reset successfully.']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
            return response()->json(['message' => 'Error resetting data: ' . $e->getMessage()], 500);
        }
    }
}
