<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhaleTransaction;
use App\Models\Coin;
use App\Services\WhaleAlertService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhaleController extends Controller {
    public function __construct(private WhaleAlertService $whaleService) {}

    public function index(Request $request): JsonResponse {
        // Sync whales every 5 minutes or if empty
        if (\App\Models\WhaleTransaction::count() < 10 || !\Illuminate\Support\Facades\Cache::has('whale_sync_time')) {
            $this->syncWhaleTransactions();
            \Illuminate\Support\Facades\Cache::put('whale_sync_time', true, 300); // 5 mins
        }

        $query = WhaleTransaction::with('coin');
        if ($type = $request->get('type')) {
            $query->where('transaction_type', $type);
        }
        if ($minUsd = $request->get('min_usd')) {
            $query->where('amount_usd', '>=', $minUsd);
        }

        $transactions = $query->latest('occurred_at')->paginate($request->get('per_page', 20));
        return response()->json($transactions);
    }

    private function syncWhaleTransactions(): void {
        try {
            $transactions = $this->whaleService->getRecentTransactions();
            foreach ($transactions as $tx) {
                // Find coin match based on symbol (e.g., BTC -> BTCUSDT)
                $coin = Coin::where('symbol', $tx['symbol'] . 'USDT')->first();

                WhaleTransaction::firstOrCreate(
                    ['tx_hash' => $tx['tx_hash']],
                    [
                        'coin_id'          => $coin?->id,
                        'source'           => $tx['source'],
                        'from_address'     => $tx['from_address'],
                        'to_address'       => $tx['to_address'],
                        'amount'           => $tx['amount'],
                        'amount_usd'       => $tx['amount_usd'],
                        'transaction_type' => $tx['transaction_type'],
                        'occurred_at'      => $tx['occurred_at']
                    ]
                );
            }
        } catch (\Exception $e) {
            // Log and fail silently to return existing database records
        }
    }
}
