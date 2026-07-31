<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentBridgeService;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\JsonResponse;

class AgentBridgeController extends Controller
{
    /**
     * Poll the status of an Antigravity bridge request.
     * Called by the frontend every N seconds after submitting an async request.
     *
     * GET /api/agent-bridge/status/{requestId}
     */
    public function status(string $requestId): JsonResponse
    {
        $settingsController = new SettingsController();
        $settings = $settingsController->getSettings();

        if (($settings['ai_provider'] ?? '') !== 'antigravity') {
            return response()->json(['error' => 'Antigravity provider not active'], 400);
        }

        $bridge = new AgentBridgeService($settings);

        if (!$bridge->isConfigured()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Antigravity bridge folder is not configured. Please set the folder path in AI Settings.',
            ], 400);
        }

        $status = $bridge->getStatus($requestId);
        return response()->json($status);
    }
}
