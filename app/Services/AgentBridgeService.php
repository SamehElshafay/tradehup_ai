<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

/**
 * AgentBridgeService
 *
 * Manages the file-based bridge between the website and the Antigravity agent.
 * Folder structure:
 *   {base}/pending/     → new requests waiting to be picked up
 *   {base}/processing/  → request being handled by the agent right now
 *   {base}/completed/   → done requests with "result" key written by agent
 *   {base}/failed/      → requests that the agent marked as failed
 */
class AgentBridgeService
{
    private string $basePath;
    private int    $timeout;       // seconds to wait before giving up polling
    private int    $pollInterval;  // seconds between each poll check

    public function __construct(array $settings = [])
    {
        $this->basePath     = rtrim($settings['agent_bridge_folder'] ?? '', '/\\');
        $this->timeout      = (int) ($settings['agent_bridge_timeout']       ?? 300);
        $this->pollInterval = (int) ($settings['agent_bridge_poll_interval'] ?? 5);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Validation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Returns true if the bridge folder is configured and exists (or was created).
     */
    public function isConfigured(): bool
    {
        if (empty($this->basePath)) {
            return false;
        }
        $this->ensureFolders();
        return is_dir($this->basePath);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Submit a new request (async — returns immediately)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Write a new request file to pending/ and return the request ID.
     * Does NOT wait for the result.
     *
     * @param  string $type          e.g. "chat", "chart_analysis", "news_analysis"
     * @param  array  $input         raw input data (messages, chart data, etc.)
     * @param  string $promptContext the human-readable instruction for the agent
     * @return string                the generated request_id (UUID)
     * @throws Exception             if the bridge folder is not configured
     */
    public function submitRequest(string $type, array $input, string $promptContext): string
    {
        if (!$this->isConfigured()) {
            throw new Exception('BRIDGE_NOT_CONFIGURED');
        }

        $requestId = (string) Str::uuid();
        $payload   = [
            'request_id'     => $requestId,
            'created_at'     => now()->toIso8601String(),
            'type'           => $type,
            'input'          => $input,
            'prompt_context' => $promptContext,
        ];

        $path = $this->pendingPath("req_{$requestId}.json");
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Log::info("AgentBridge: submitted {$type} request {$requestId}");
        return $requestId;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Poll for status (called by the status endpoint)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get the current status of a request.
     *
     * Returns an array with keys:
     *   status         → 'pending' | 'processing' | 'completed' | 'failed'
     *   result         → (only when completed) the agent's response string
     *   error          → (only when failed)    the error message string
     *   queue_position → (only when pending)   how many requests are ahead
     *   estimated_wait → (only when pending)   estimated seconds until completion
     */
    public function getStatus(string $requestId): array
    {
        $filename = "req_{$requestId}.json";

        // Completed?
        $completedPath = $this->completedPath($filename);
        if (file_exists($completedPath)) {
            $data = json_decode(file_get_contents($completedPath), true) ?? [];
            return [
                'status' => 'completed',
                'result' => $data['result'] ?? '',
            ];
        }

        // Failed?
        $failedPath = $this->failedPath($filename);
        if (file_exists($failedPath)) {
            $data = json_decode(file_get_contents($failedPath), true) ?? [];
            return [
                'status' => 'failed',
                'error'  => $data['error'] ?? 'Unknown error',
            ];
        }

        // Processing?
        $processingPath = $this->processingPath($filename);
        if (file_exists($processingPath)) {
            return ['status' => 'processing'];
        }

        // Pending — calculate queue position
        $pendingPath = $this->pendingPath($filename);
        if (file_exists($pendingPath)) {
            $position      = $this->getQueuePosition($requestId);
            $estimatedWait = ($position + 1) * 40; // ~40 s per request
            return [
                'status'         => 'pending',
                'queue_position' => $position,
                'estimated_wait' => $estimatedWait,
            ];
        }

        // File not found anywhere — probably expired or never existed
        return ['status' => 'not_found'];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Queue helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * How many requests in pending/ were created BEFORE this one?
     */
    public function getQueuePosition(string $requestId): int
    {
        $pendingFiles = glob($this->pendingPath('req_*.json')) ?: [];

        // Sort by created_at inside each file (fallback: file mtime)
        usort($pendingFiles, fn($a, $b) => filemtime($a) <=> filemtime($b));

        $myFile = $this->pendingPath("req_{$requestId}.json");
        $pos    = 0;
        foreach ($pendingFiles as $f) {
            if (realpath($f) === realpath($myFile)) break;
            $pos++;
        }

        // Add 1 if something is currently being processed (it'll finish first)
        $processingFiles = glob($this->processingPath('req_*.json')) ?: [];
        if (!empty($processingFiles)) {
            $pos++;
        }

        return $pos;
    }

    /**
     * Total files currently in pending/ (useful for queue info on submit).
     */
    public function getPendingCount(): int
    {
        return count(glob($this->pendingPath('req_*.json')) ?: []);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────────────

    private function ensureFolders(): void
    {
        foreach (['pending', 'processing', 'completed', 'failed'] as $sub) {
            $dir = $this->basePath . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function pendingPath(string $file = ''): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'pending' . ($file ? DIRECTORY_SEPARATOR . $file : '');
    }

    private function processingPath(string $file = ''): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'processing' . ($file ? DIRECTORY_SEPARATOR . $file : '');
    }

    private function completedPath(string $file = ''): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'completed' . ($file ? DIRECTORY_SEPARATOR . $file : '');
    }

    private function failedPath(string $file = ''): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'failed' . ($file ? DIRECTORY_SEPARATOR . $file : '');
    }
}
