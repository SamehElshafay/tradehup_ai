<?php
namespace App\Jobs;
use App\Models\NewsItem;
use App\Services\CryptoPanicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchNewsJob implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;

    public function handle(CryptoPanicService $newsService): void {
        try {
            $news = $newsService->getLatestNews('news', 30);
            foreach ($news as $item) {
                NewsItem::firstOrCreate(
                    ['url' => $item['url']],
                    [
                        'source'          => $item['source'],
                        'title'           => $item['title'],
                        'type'            => $item['type'],
                        'sentiment'       => $item['sentiment'],
                        'sentiment_score' => $item['sentiment_score'],
                        'coins_mentioned' => $item['coins_mentioned'],
                        'published_at'    => $item['published_at']
                    ]
                );
            }
            Log::info('News fetched', ['count' => count($news)]);
        } catch (\Exception $e) {
            Log::error('FetchNewsJob failed', ['error' => $e->getMessage()]);
        }
    }
}
