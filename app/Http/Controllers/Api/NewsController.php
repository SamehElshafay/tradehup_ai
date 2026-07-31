<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NewsController extends Controller {
    public function index(Request $request): JsonResponse {
        // Sync news every 15 minutes
        if (!\Illuminate\Support\Facades\Cache::has('news_sync_time')) {
            $this->syncNews();
            \Illuminate\Support\Facades\Cache::put('news_sync_time', true, 900); // 15 mins
        }

        $query = NewsItem::query();
        if ($type = $request->get('type')) $query->where('type', $type);
        if ($sentiment = $request->get('sentiment')) $query->where('sentiment', $sentiment);
        if ($coin = $request->get('coin')) $query->whereJsonContains('coins_mentioned', strtoupper($coin));
        $news = $query->latest('published_at')->paginate($request->get('per_page', 20));
        return response()->json($news);
    }

    private function syncNews(): void {
        try {
            $service = app(\App\Services\CryptoPanicService::class);
            $latest = $service->getLatestNews('news', 30);
            foreach ($latest as $n) {
                NewsItem::updateOrCreate(
                    ['url' => $n['url']], // unique key
                    [
                        'source' => $n['source'],
                        'title' => $n['title'],
                        'type' => $n['type'],
                        'sentiment' => $n['sentiment'],
                        'sentiment_score' => $n['sentiment_score'] ?? 50,
                        'coins_mentioned' => $n['coins_mentioned'],
                        'published_at' => $n['published_at'],
                    ]
                );
            }
        } catch (\Exception $e) {
            // Fail silently
        }
    }

    public function read(Request $request, int $id): JsonResponse {
        // AI generation might take longer than 30s
        set_time_limit(300);

        $news = NewsItem::findOrFail($id);
        $lang = $request->get('lang', 'Original');

        $imageKey = 'news_img_' . $id;
        $image = \Illuminate\Support\Facades\Cache::get($imageKey);

        $cacheKey = 'news_read_' . $id . '_' . $lang;
        
        $content = \Illuminate\Support\Facades\Cache::remember($cacheKey, 86400, function () use ($news, $lang, $imageKey, &$image) {
            try {
                // Fetch article HTML
                $html = \Illuminate\Support\Facades\Http::timeout(10)->withoutVerifying()->get($news->url)->body();
                
                if (!$image) {
                    if (preg_match('/<meta property="og:image"\s+content="([^"]+)"/i', $html, $m) || preg_match('/<meta content="([^"]+)"\s+property="og:image"/i', $html, $m)) {
                        $image = $m[1];
                        \Illuminate\Support\Facades\Cache::put($imageKey, $image, 86400);
                    }
                }

                // Very basic stripping to get text
                $text = strip_tags($html);
                $text = preg_replace('/\s+/', ' ', $text);
                $text = substr($text, 0, 6000); // limit to 6000 chars for context window

                if ($lang === 'Original') {
                    $desc = '';
                    if (preg_match('/<meta[^>]*property="og:description"[^>]*content="([^"]*)"/i', $html, $m) || 
                        preg_match('/<meta[^>]*name="description"[^>]*content="([^"]*)"/i', $html, $m)) {
                        $desc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                    }
                    
                    if (strlen(trim($desc)) > 20) {
                        return $desc;
                    }
                    
                    return substr(trim($text), 0, 400) . "...";
                } else {
                    $prompt = "قم بقراءة المقال الإخباري التالي عن العملات الرقمية، واكتب ملخصاً احترافياً له باللغة " . $lang . " فقط. تجاهل أي قوائم أو روابط موجودة في النص وركز على الخبر الأساسي.\n\nعنوان الخبر: " . $news->title . "\n\nتفاصيل الخبر: " . $text;
                    $sys = "أنت خبير ومترجم محترف في مجال العملات الرقمية (Crypto). يجب عليك دائماً الرد باللغة " . $lang . " فقط. قدم ملخصاً واضحاً واحترافياً للخبر.";
                    
                    $ai = app(\App\Services\OpenRouterService::class);
                    return $ai->chat($prompt, $sys, $ai->newsModel);
                }
            } catch (\Exception $e) {
                return "Failed to load content. " . $e->getMessage();
            }
        });

        // if image was found during Cache::remember but outside of the outer scope's knowledge initially:
        if (!$image) $image = \Illuminate\Support\Facades\Cache::get($imageKey);

        return response()->json([
            'id' => $news->id,
            'title' => $news->title,
            'content' => $content,
            'image' => $image
        ]);
    }
}
