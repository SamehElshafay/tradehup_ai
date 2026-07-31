<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_id')->nullable()->constrained('analyses')->nullOnDelete();
            $table->enum('timeframe', ['1m','5m','15m','30m','1h','4h','1d','1w']);
            $table->enum('action', ['BUY','SELL','HOLD','WAIT']);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('tp1', 20, 8)->nullable();
            $table->decimal('tp2', 20, 8)->nullable();
            $table->decimal('tp3', 20, 8)->nullable();
            $table->decimal('sl', 20, 8);
            $table->string('risk_reward', 20)->nullable();
            $table->tinyInteger('confidence')->default(0);
            $table->text('reasoning')->nullable();
            $table->json('confluences')->nullable();
            $table->decimal('sentiment_score', 5, 2)->nullable();
            $table->json('whale_activity')->nullable();
            $table->json('liquidity_data')->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->enum('status', ['active','expired','hit_tp1','hit_tp2','hit_tp3','hit_sl'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['coin_id', 'status']);
            $table->index(['coin_id', 'timeframe']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('recommendations');
    }
};
