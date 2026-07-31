<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Coin;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecommendationController extends Controller {
    public function index(Request $request, string $symbol): JsonResponse {
        $coin = Coin::where('symbol', strtoupper($symbol))->firstOrFail();
        
        $query = Recommendation::with(['coin', 'analysis'])->where('coin_id', $coin->id);

        // Filter by timeframe
        if ($tf = $request->get('timeframe')) {
            $query->where('timeframe', $tf);
        }

        // Filter by status (won, lost, active, neutral, or specific status)
        if ($status = $request->get('status')) {
            if ($status === 'won') {
                $query->whereIn('status', ['hit_tp1', 'hit_tp2', 'hit_tp3']);
            } elseif ($status === 'lost') {
                $query->where('status', 'hit_sl');
            } elseif ($status === 'active') {
                $query->whereIn('status', ['active', 'pending'])->whereIn('action', ['BUY', 'SELL']);
            } elseif ($status === 'neutral') {
                $query->where('action', 'WAIT');
            } elseif ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Sort by confidence or date
        $sortBy = $request->get('sort_by', 'created_at');
        if ($sortBy === 'confidence') {
            $query->orderBy('confidence', 'desc')->latest();
        } else {
            $query->latest();
        }

        $recs = $query->paginate($request->get('per_page', 20));

        // Calculate Win, Loss, and Pending/Active Statistics (Filtered by timeframe if provided)
        $statsBaseQuery = Recommendation::where('coin_id', $coin->id);
        if ($tf) {
            $statsBaseQuery->where('timeframe', $tf);
        }

        $totalSignalsCount = (clone $statsBaseQuery)->count();

        $allTradeRecs = (clone $statsBaseQuery)
            ->whereIn('action', ['BUY', 'SELL'])
            ->get();

        $totalTrades   = $allTradeRecs->count();
        $winningTrades = $allTradeRecs->filter(fn($r) => in_array($r->status, ['hit_tp1', 'hit_tp2', 'hit_tp3']))->count();
        $losingTrades  = $allTradeRecs->filter(fn($r) => $r->status === 'hit_sl')->count();
        $pendingTrades = $allTradeRecs->filter(fn($r) => in_array($r->status, ['active', 'pending']))->count();

        $winRate     = $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0;
        $lossRate    = $totalTrades > 0 ? round(($losingTrades / $totalTrades) * 100, 1) : 0;
        $pendingRate = $totalTrades > 0 ? round(($pendingTrades / $totalTrades) * 100, 1) : 0;

        return response()->json([
            'recommendations' => $recs,
            'stats' => [
                'total_signals'   => $totalSignalsCount,
                'total_trades'    => $totalTrades,
                'winning_signals' => $winningTrades,
                'losing_signals'  => $losingTrades,
                'pending_signals' => $pendingTrades,
                'win_rate'        => $winRate,
                'loss_rate'       => $lossRate,
                'pending_rate'    => $pendingRate,
            ]
        ]);
    }
    public function latest(Request $request, string $symbol): JsonResponse {
        $coin = Coin::where('symbol', strtoupper($symbol))->firstOrFail();
        $timeframe = $request->get('timeframe', '4h');
        $rec = Recommendation::where('coin_id', $coin->id)
            ->where('timeframe', $timeframe)
            ->where('status', 'active')
            ->latest()->first();
        if (!$rec) return response()->json(['message' => 'No active recommendation found'], 404);
        return response()->json(['recommendation' => $rec]);
    }
}
