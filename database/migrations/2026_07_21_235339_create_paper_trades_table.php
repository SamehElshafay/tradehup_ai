<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('paper_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('paper_trading_sessions')->cascadeOnDelete();
            $table->foreignId('coin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained('recommendations')->nullOnDelete();
            $table->enum('type', ['BUY', 'SELL']);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8);
            $table->decimal('tp1', 20, 8)->nullable();
            $table->decimal('tp2', 20, 8)->nullable();
            $table->decimal('tp3', 20, 8)->nullable();
            $table->decimal('sl', 20, 8);
            $table->decimal('pnl', 15, 4)->nullable();
            $table->decimal('pnl_percent', 10, 4)->nullable();
            $table->enum('status', ['open','closed_tp1','closed_tp2','closed_tp3','closed_sl','closed_manual'])->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('paper_trades');
    }
};
