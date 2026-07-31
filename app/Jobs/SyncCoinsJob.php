<?php
namespace App\Jobs;
use App\Models\Coin;
use App\Services\PythonTAService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCoinsJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 60;

    public function handle(PythonTAService $taService): void {
        try {
            $topCoins = $taService->getTopCoins(100);
            foreach ($topCoins as $coinData) {
                $baseAsset = str_replace('USDT', '', $coinData['symbol']);
                Coin::updateOrCreate(
                    ['symbol' => $coinData['symbol']],
                    [
                        'name'             => $baseAsset,
                        'base_asset'       => $baseAsset,
                        'quote_asset'      => 'USDT',
                        'current_price'    => $coinData['price'],
                        'price_change_24h' => $coinData['price_change_24h'],
                        'volume_24h'       => $coinData['volume_24h'],
                        'high_24h'         => $coinData['high_24h'],
                        'low_24h'          => $coinData['low_24h'],
                        'is_active'        => true,
                        'last_synced_at'   => now()
                    ]
                );
            }
            Log::info('Coins synced successfully', ['count' => count($topCoins)]);
        } catch (\Exception $e) {
            Log::error('SyncCoinsJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
