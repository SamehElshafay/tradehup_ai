<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\OpenRouterService;
use App\Services\AgentBridgeService;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ChatToolService;

class ChatController extends Controller {
    public function __construct(
        private OpenRouterService $aiService,
        private ChatToolService $toolService
    ) {}

    public function conversations(Request $request): JsonResponse {
        $conversations = $request->user()->chatConversations()->with('lastMessage')->latest()->get();
        return response()->json(['conversations' => $conversations]);
    }

    public function newConversation(Request $request): JsonResponse {
        $conversation = ChatConversation::create([
            'user_id' => $request->user()->id,
            'title'   => $request->get('title', 'New Chat')
        ]);
        return response()->json(['conversation' => $conversation], 201);
    }

    public function messages(Request $request, int $id): JsonResponse {
        $conversation = ChatConversation::where('user_id', $request->user()->id)->findOrFail($id);
        $messages = $conversation->messages()->oldest()->get();
        return response()->json(['messages' => $messages]);
    }

    public function sendMessage(Request $request, int $id): JsonResponse {
        set_time_limit(300);
        $request->validate(['message' => 'required|string|max:2000']);
        $conversation = ChatConversation::where('user_id', $request->user()->id)->findOrFail($id);

        // Save user message first
        ChatMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $request->message]);

        $settingsCtrl = new SettingsController();
        $settings     = $settingsCtrl->getSettings();

        // ── Antigravity async path ──────────────────────────────────────
        if (($settings['ai_provider'] ?? '') === 'antigravity') {
            return $this->handleAntigravityChat($request, $conversation, $settings);
        }

        // ── Standard synchronous path ──────────────────────────────────
        $history = $conversation->messages()->oldest()->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])->toArray();

        $systemPrompt = $this->buildSystemPrompt();
        array_unshift($history, ['role' => 'system', 'content' => $systemPrompt]);

        $tools = $this->toolService->getToolDefinitions();
        $aiResponse = $this->aiService->chatWithHistory($history, null, $tools);

        // Tool Calling Loop (max 5 iterations to prevent infinite loops)
        $iterations = 0;
        while (is_array($aiResponse) && isset($aiResponse['tool_calls']) && $iterations < 5) {
            $iterations++;
            // Append the assistant's tool call request to the history
            $history[] = $aiResponse;
            
            foreach ($aiResponse['tool_calls'] as $toolCall) {
                $funcName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'], true) ?? [];
                
                $result = $this->toolService->executeToolCall($funcName, $args, $request->user()->id);
                
                $history[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $funcName,
                    'content' => $result
                ];
            }
            
            // Send the updated history back to the LLM to get the final answer
            $aiResponse = $this->aiService->chatWithHistory($history, null, $tools);
        }

        // Fallback if it returned an array without tool calls (or max iterations reached)
        if (is_array($aiResponse)) {
            $aiResponse = $aiResponse['content'] ?? 'An error occurred during tool execution.';
        }

        $aiMessage = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $aiResponse,
            'ai_model'        => $settings['chat_model'] ?? 'unknown',
        ]);

        if ($conversation->title === 'New Chat' && $conversation->messages()->count() <= 3) {
            $conversation->update(['title' => substr($request->message, 0, 50)]);
        }

        return response()->json(['message' => $aiMessage]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Antigravity async handler
    // ─────────────────────────────────────────────────────────────────

    private function handleAntigravityChat(Request $request, ChatConversation $conversation, array $settings): JsonResponse
    {
        $bridge = new AgentBridgeService($settings);

        // Guard: folder not configured
        if (!$bridge->isConfigured()) {
            return response()->json([
                'error'   => 'BRIDGE_NOT_CONFIGURED',
                'message' => 'Please set the Antigravity bridge folder path in AI Settings before using this provider.',
            ], 422);
        }

        // Build messages history for the agent
        $history = $conversation->messages()->oldest()->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])->toArray();

        $systemPrompt = $this->buildSystemPrompt();

        // Submit request async — returns immediately with a requestId
        $requestId = $bridge->submitRequest(
            type:          'chat',
            input:         ['messages' => $history],
            promptContext: $systemPrompt
        );

        // Queue info
        $queuePosition = $bridge->getQueuePosition($requestId);
        $estimatedWait = ($queuePosition + 1) * 40;

        // Create a placeholder AI message in "pending" state
        $placeholder = ChatMessage::create([
            'conversation_id'   => $conversation->id,
            'role'              => 'assistant',
            'content'           => '⏳ Waiting for Antigravity agent...',
            'ai_model'          => 'antigravity',
            'bridge_request_id' => $requestId,
            'bridge_status'     => 'pending',
        ]);

        if ($conversation->title === 'New Chat' && $conversation->messages()->count() <= 3) {
            $conversation->update(['title' => substr($request->message, 0, 50)]);
        }

        return response()->json([
            'message'        => $placeholder,
            'bridge_pending' => true,
            'request_id'     => $requestId,
            'queue_position' => $queuePosition,
            'estimated_wait' => $estimatedWait,
            'message_id'     => $placeholder->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Poll for the result of a pending bridge request and update the message
    // ─────────────────────────────────────────────────────────────────

    public function bridgeStatus(Request $request, int $conversationId, string $requestId): JsonResponse
    {
        $conversation = ChatConversation::where('user_id', $request->user()->id)->findOrFail($conversationId);

        $settingsCtrl = new SettingsController();
        $settings     = $settingsCtrl->getSettings();

        $bridge = new AgentBridgeService($settings);
        $status = $bridge->getStatus($requestId);

        // Find the placeholder message
        $message = ChatMessage::where('conversation_id', $conversation->id)
            ->where('bridge_request_id', $requestId)
            ->first();

        if (!$message) {
            return response()->json(['status' => 'not_found'], 404);
        }

        // Update the message with the final result
        if ($status['status'] === 'completed') {
            $message->update([
                'content'       => $status['result'] ?? '',
                'bridge_status' => 'completed',
            ]);
        } elseif ($status['status'] === 'failed') {
            $message->update([
                'content'       => '❌ Antigravity agent encountered an error: ' . ($status['error'] ?? 'Unknown error'),
                'bridge_status' => 'failed',
            ]);
        }

        return response()->json(array_merge($status, [
            'message' => $message->fresh(),
        ]));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Shared system prompt
    // ─────────────────────────────────────────────────────────────────

    private function buildSystemPrompt(): string
    {
        return "أنت TradeSight AI، مساعد تحليل فني وبيانات سوق داخل منصة تداول متخصصة.\n"
            . "المستخدم الذي يتحدث معك هو مستخدم للمنصة ويعلم أنها أداة تحليل بيانات وليست استشارة مالية مرخصة.\n\n"
            . "دورك:\n"
            . "- تحليل البيانات الفنية (SMC, Order Blocks, FVG, Harmonic, Volume Profile) وشرحها بأسلوب احترافي.\n"
            . "- الإجابة على الأسئلة الخاصة بالتوصيات المعروضة (Entry/TP/SL) وتوضيح سبب الأرقام بناءً على المؤشرات المحسوبة.\n"
            . "- شرح المفاهيم الفنية واستراتيجيات التداول المختلفة.\n\n"
            . "قواعد الرد:\n"
            . "1. لديك أدوات (Tools/Functions) مبرمجة لجلب الأسعار الحية، وعمل التحليل الفني، وجلب بيانات الصفقات. **استخدمها دائماً** عندما يسألك المستخدم عن أسعار أو تحليل أو صفقات، وإياك أن تقول 'لا أستطيع الوصول للبيانات الحية'.\n"
            . "2. لا ترد برفض عام مثل 'لا أستطيع تقديم نصيحة مالية' — قدم تحليلاً فنياً مبنياً على البيانات عبر استخدام أدواتك.\n"
            . "3. إذا سألك المستخدم 'أشتري أم لا؟'، قم باستدعاء أداة التحليل الفني، ثم أجب بالبيانات: 'بناءً على [المؤشرات المحسوبة]، الاتجاه الحالي هو [كذا]، والقرار النهائي وإدارة المخاطر تظل مسؤوليتك'.\n"
            . "4. تجنب التأكيدات المطلقة مثل 'أضمن لك'، وقدم تحليلاً واضحاً وموضوعياً.\n"
            . "5. أجب دائماً بنوع اللغة التي يكتب بها المستخدم (عربي / إنجليزي).\n"
            . "6. CRITICAL: Never output any Chinese characters or Asian symbols. Use ONLY Arabic and English.\n"
            . "الوقت الحالي: " . now()->toDateTimeString();
    }
}
