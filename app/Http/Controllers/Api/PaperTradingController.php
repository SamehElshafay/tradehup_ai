<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Coin;
use App\Models\PaperTrade;
use App\Models\PaperTradingSession;
use App\Services\PythonTAService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PaperTradingController extends Controller {
    public function __construct(private PythonTAService $taService) {}

    public function sessions(Request $request): JsonResponse {
        $sessions = $request->user()->paperSessions()->latest()->get();
        return response()->json(['sessions' => $sessions]);
    }

    public function createSession(Request $request): JsonResponse {
        $validator = Validator::make($request->all(), [
            'initial_balance' => 'sometimes|numeric|min:100|max:1000000',
            'target_balance'  => 'sometimes|numeric|min:0'
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $user = $request->user();
        $activeSession = $user->activePaperSession;
        if ($activeSession) return response()->json(['message' => 'You already have an active session', 'session' => $activeSession], 409);
        $balance = $request->get('initial_balance', 1000.00);
        $session = PaperTradingSession::create([
            'user_id' => $user->id,
            'initial_balance' => $balance,
            'current_balance' => $balance,
            'target_balance'  => $request->get('target_balance'),
            'status' => 'active',
            'started_at' => now()
        ]);
        return response()->json(['session' => $session], 201);
    }

    public function session(Request $request, int $id): JsonResponse {
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($id);
        $session->load('trades.coin');
        $session->pnl_percent = $session->pnlPercent();
        return response()->json(['session' => $session]);
    }

    public function openTrade(Request $request): JsonResponse {
        $validator = Validator::make($request->all(), [
            'session_id'         => 'required|exists:paper_trading_sessions,id',
            'coin_id'            => 'required|exists:coins,id',
            'recommendation_id'  => 'sometimes|exists:recommendations,id',
            'type'               => 'required|in:BUY,SELL',
            'quantity'           => 'required|numeric|min:0.00001',
            'tp1'                => 'sometimes|numeric',
            'tp2'                => 'sometimes|numeric',
            'tp3'                => 'sometimes|numeric',
            'sl'                 => 'required|numeric',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->where('status', 'active')->findOrFail($request->session_id);
        $coin = Coin::findOrFail($request->coin_id);
        $entryPrice = $this->taService->getPrice($coin->symbol) ?? $coin->current_price;
        $cost = $entryPrice * $request->quantity;
        if ($cost > $session->current_balance) {
            // Auto-top-up balance to allow unlimited trade tracking
            $session->increment('current_balance', 10000);
            $session->increment('initial_balance', 10000);
        }
        $trade = PaperTrade::create([
            'session_id'        => $session->id,
            'coin_id'           => $request->coin_id,
            'recommendation_id' => $request->recommendation_id,
            'type'              => $request->type,
            'entry_price'       => $entryPrice,
            'quantity'          => $request->quantity,
            'tp1'               => $request->tp1,
            'tp2'               => $request->tp2,
            'tp3'               => $request->tp3,
            'sl'                => $request->sl,
            'close_target'      => $request->close_target ?? 'tp3',
            'history'           => [],
            'status'            => 'open',
            'opened_at'         => now()
        ]);
        $session->decrement('current_balance', $cost);
        return response()->json(['trade' => $trade->load('coin')], 201);
    }

    public function closeTrade(Request $request, int $id): JsonResponse {
        $trade = PaperTrade::where('status', 'open')->findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        $exitPrice = $this->taService->getPrice($trade->coin->symbol) ?? $trade->entry_price;
        $pnl = ($exitPrice - $trade->entry_price) * $trade->quantity * ($trade->type === 'BUY' ? 1 : -1);
        $pnlPercent = (($exitPrice - $trade->entry_price) / $trade->entry_price) * 100 * ($trade->type === 'BUY' ? 1 : -1);
        $trade->update(['exit_price' => $exitPrice, 'pnl' => $pnl, 'pnl_percent' => $pnlPercent, 'status' => 'closed_manual', 'closed_at' => now()]);
        $returnAmount = $exitPrice * $trade->quantity;
        $session->increment('current_balance', $returnAmount);
        $this->updateSessionStats($session);
        return response()->json(['trade' => $trade, 'pnl' => $pnl, 'new_balance' => $session->fresh()->current_balance]);
    }

    public function updateTarget(Request $request, int $id): JsonResponse {
        $request->validate(['close_target' => 'required|in:tp1,tp2,tp3']);
        $trade = PaperTrade::where('status', 'open')->findOrFail($id);
        $session = PaperTradingSession::where('user_id', $request->user()->id)->findOrFail($trade->session_id);
        $trade->update(['close_target' => $request->close_target]);
        return response()->json(['message' => 'Target updated successfully', 'trade' => $trade]);
    }

    public function trades(Request $request): JsonResponse {
        $user = $request->user();
        $sessionId = $request->get('session_id');
        $query = PaperTrade::whereHas('session', fn($q) => $q->where('user_id', $user->id));
        if ($sessionId) $query->where('session_id', $sessionId);
        if ($status = $request->get('status')) $query->where('status', $status);
        $trades = $query->with(['coin', 'recommendation.analysis'])->latest('opened_at')->paginate(20);
        return response()->json($trades);
    }

    private function updateSessionStats(PaperTradingSession $session): void {
        $closedTrades = $session->trades()->whereNotIn('status', ['open'])->get();
        $total = $closedTrades->count();
        $winning = $closedTrades->where('pnl', '>', 0)->count();
        $session->update([
            'total_trades'   => $total,
            'winning_trades' => $winning,
            'win_rate'       => $total > 0 ? round(($winning / $total) * 100, 2) : 0
        ]);
    }
}
