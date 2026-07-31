<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoinController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\PaperTradingController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\WhaleController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AgentBridgeController;

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Public routes
Route::get('ai-settings', [SettingsController::class, 'index']);
Route::get('ai-settings/ollama-models', [SettingsController::class, 'ollamaModels']);
Route::get('coins', [CoinController::class, 'index']);
Route::get('coins/top', [CoinController::class, 'top']);
Route::get('coins/{symbol}', [CoinController::class, 'show']);
Route::get('coins/{symbol}/ohlcv', [CoinController::class, 'ohlcv']);
Route::get('coins/{symbol}/price', [CoinController::class, 'price']);
Route::get('/news', [NewsController::class, 'index']);
Route::get('/news/{id}/read', [NewsController::class, 'read']);
Route::get('/whales', [WhaleController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Favorites & Follows
    Route::post('coins/{id}/favorite', [CoinController::class, 'favorite']);
    Route::delete('coins/{id}/favorite', [CoinController::class, 'unfavorite']);
    Route::post('coins/{id}/follow', [CoinController::class, 'follow']);
    Route::delete('coins/{id}/follow', [CoinController::class, 'unfollow']);
    Route::get('user/favorites', [CoinController::class, 'favorites']);
    Route::get('user/follows', [CoinController::class, 'follows']);

    // Analysis & Recommendations
    Route::post('analysis/{symbol}/{timeframe}', [AnalysisController::class, 'analyze']);
    Route::get('analysis/{symbol}/{timeframe}/latest', [AnalysisController::class, 'latest']);
    Route::get('analysis/{symbol}/{timeframe}/bridge/{requestId}', [AnalysisController::class, 'bridgeStatus']);
    Route::get('recommendations/{symbol}', [RecommendationController::class, 'index']);
    Route::get('recommendations/{symbol}/latest', [RecommendationController::class, 'latest']);
    Route::get('opportunities', [OpportunityController::class, 'index']);
    Route::post('opportunities/scan', [OpportunityController::class, 'scan']);
    Route::get('opportunities/stats', [OpportunityController::class, 'stats']);
    Route::get('opportunities/scan/status', [OpportunityController::class, 'scanStatus']);

    // Paper Trading
    Route::get('paper-trading/sessions', [PaperTradingController::class, 'sessions']);
    Route::post('paper-trading/sessions', [PaperTradingController::class, 'createSession']);
    Route::get('paper-trading/sessions/{id}', [PaperTradingController::class, 'session']);
    Route::post('paper-trading/trades', [PaperTradingController::class, 'openTrade']);
    Route::put('paper-trading/trades/{id}/close', [PaperTradingController::class, 'closeTrade']);
    Route::put('paper-trading/trades/{id}/target', [PaperTradingController::class, 'updateTarget']);
    Route::get('paper-trading/trades', [PaperTradingController::class, 'trades']);

    // Chat
    Route::get('chat', [ChatController::class, 'conversations']);
    Route::post('chat', [ChatController::class, 'newConversation']);
    Route::get('chat/{id}/messages', [ChatController::class, 'messages']);
    Route::post('chat/{id}/messages', [ChatController::class, 'sendMessage']);
    // Antigravity bridge polling: frontend calls this every N seconds to get the result
    Route::get('chat/{id}/bridge-status/{requestId}', [ChatController::class, 'bridgeStatus']);

    // Agent Bridge status (general)
    Route::get('agent-bridge/status/{requestId}', [AgentBridgeController::class, 'status']);

    // AI Settings
    Route::put('ai-settings', [SettingsController::class, 'update']);
    Route::post('ai-settings/halt', [SettingsController::class, 'halt']);
    Route::post('ai-settings/reset-data', [SettingsController::class, 'resetData']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::put('notifications/read-all', [NotificationController::class, 'markAllRead']);
});
