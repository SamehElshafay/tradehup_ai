<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ReportController extends Controller {
    public function opportunitiesHistory(Request $request): JsonResponse {
        $limit = $request->get('limit', 1000);
        
        $history = Recommendation::with('coin')->select('id', 'coin_id', 'action', 'status', 'created_at', 'updated_at', 'pnl_percent')
            ->whereIn('status', ['hit_tp1', 'hit_tp2', 'hit_tp3', 'hit_sl'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
            
        return response()->json([
            'data' => $history
        ]);
    }
    
    public function trackedTradesHistory(Request $request): JsonResponse {
        $limit = $request->get('limit', 1000);
        $history = \App\Models\PaperTrade::with('coin:id,symbol,base_asset')
        ->select('id', 'coin_id', 'session_id', 'type', 'status', 'pnl', 'pnl_percent', 'highest_target_hit', 'opened_at', 'closed_at', 'entry_price', 'exit_price', 'tp1', 'sl')
        ->whereHas('session', function($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })
        ->where('status', '!=', 'open')
        ->orderByDesc('opened_at')
        ->limit($limit)
        ->get();
        return response()->json(['data' => $history]);
    }

    /**
     * Return how many times each coin appeared as a scan opportunity today.
     * "Today" = midnight (00:00:00) → 23:59:59 in server local time.
     */
    public function dailyScanCounts(): JsonResponse {
        $todayStart = Carbon::today();          // 00:00:00 today
        $todayEnd   = Carbon::tomorrow();       // 00:00:00 tomorrow (exclusive)

        $rows = Recommendation::with('coin:id,symbol')
            ->select('coin_id')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereIn('action', ['BUY', 'SELL'])
            ->get();

        // Build symbol => count map
        $counts = [];
        foreach ($rows as $row) {
            $symbol = $row->coin?->symbol;
            if (!$symbol) continue;
            $counts[$symbol] = ($counts[$symbol] ?? 0) + 1;
        }

        return response()->json(['data' => $counts]);
    }
}
