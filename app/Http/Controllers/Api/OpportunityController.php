<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OpportunityController extends Controller {
    public function index(Request $request): JsonResponse {
        $query = Recommendation::with(['coin', 'analysis'])
            ->where('status', 'active')
            ->where('action', '!=', 'WAIT')
            ->where('confidence', '>=', $request->get('min_confidence', 60));
        if ($action = $request->get('action')) $query->where('action', $action);
        if ($timeframe = $request->get('timeframe')) $query->where('timeframe', $timeframe);
        $opportunities = $query->orderByDesc('confidence')->paginate($request->get('per_page', 20));
        return response()->json($opportunities);
    }

    public function scan(Request $request): JsonResponse {
        \App\Jobs\ScanMarketOpportunitiesJob::dispatch();
        return response()->json([
            'message' => 'Market scan started in the background. You will be notified when opportunities are found.'
        ]);
    }

    /**
     * Lets the frontend show a persistent "scanning" indicator that's correct even if
     * the page was opened/refreshed after an automatic (scheduled) scan already started,
     * instead of relying only on live broadcast events from the currently open tab.
     */
    public function scanStatus(): JsonResponse {
        return response()->json([
            'scanning' => (bool) \Illuminate\Support\Facades\Cache::get('ai_scan_running', false),
        ]);
    }

    /**
     * Aggregate win-rate stats across all closed recommendations (hit_tp1/2/3 vs hit_sl),
     * the same way PaperTradingSession already tracks win_rate — so overall AI accuracy
     * can be judged from data, not from a single anecdotal outcome.
     */
    public function stats(Request $request): JsonResponse {
        $query = Recommendation::whereIn('status', ['hit_tp1', 'hit_tp2', 'hit_tp3', 'hit_sl']);
        if ($timeframe = $request->get('timeframe')) $query->where('timeframe', $timeframe);

        $counts = (clone $query)->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $wins   = ($counts['hit_tp1'] ?? 0) + ($counts['hit_tp2'] ?? 0) + ($counts['hit_tp3'] ?? 0);
        $losses = $counts['hit_sl'] ?? 0;
        $totalClosed = $wins + $losses;

        return response()->json([
            'total_closed'  => $totalClosed,
            'wins'          => $wins,
            'losses'        => $losses,
            'win_rate'      => $totalClosed > 0 ? round(($wins / $totalClosed) * 100, 2) : 0,
            'breakdown'     => [
                'hit_tp1' => $counts['hit_tp1'] ?? 0,
                'hit_tp2' => $counts['hit_tp2'] ?? 0,
                'hit_tp3' => $counts['hit_tp3'] ?? 0,
                'hit_sl'  => $counts['hit_sl'] ?? 0,
            ],
            'active_count'  => Recommendation::where('status', 'active')
                ->where('action', '!=', 'WAIT')
                ->when($timeframe, fn($q) => $q->where('timeframe', $timeframe))
                ->count(),
        ]);
    }
}
