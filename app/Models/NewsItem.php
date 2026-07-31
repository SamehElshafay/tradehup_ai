<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class NewsItem extends Model {
    protected $fillable = [
        'source', 'title', 'url', 'type', 'sentiment',
        'sentiment_score', 'coins_mentioned', 'published_at'
    ];
    protected $casts = [
        'coins_mentioned' => 'array', 'sentiment_score' => 'float',
        'published_at' => 'datetime'
    ];
}
