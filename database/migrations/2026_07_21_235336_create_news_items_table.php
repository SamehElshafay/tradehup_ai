<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->text('title');
            $table->string('url', 1000);
            $table->enum('type', ['crypto', 'economic', 'political'])->default('crypto');
            $table->enum('sentiment', ['bullish', 'bearish', 'neutral', 'panic'])->default('neutral');
            $table->decimal('sentiment_score', 5, 2)->default(0);
            $table->json('coins_mentioned')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['type', 'sentiment']);
            $table->index('published_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('news_items');
    }
};
