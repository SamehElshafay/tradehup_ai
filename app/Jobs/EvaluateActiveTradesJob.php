<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Recommendation;
use App\Services\TradeTrackerService;
use Illuminate\Support\Facades\Log;

class EvaluateActiveTradesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(TradeTrackerService $tracker): void
    {
        Log::info("EvaluateActiveTradesJob started.");

        // Expire WAIT recommendations — there's no entry/TP/SL to track for them.
        Recommendation::where('action', 'WAIT')
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        // Process BUY/SELL active trades
        $activeRecs = Recommendation::where('status', 'active')
            ->whereIn('action', ['BUY', 'SELL'])
            ->get();

        foreach ($activeRecs as $rec) {
            $tracker->evaluateChronologically($rec);
        }

        Log::info("EvaluateActiveTradesJob finished. Evaluated " . $activeRecs->count() . " active trades.");
    }
}
