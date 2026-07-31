<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('paper_trading_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('initial_balance', 15, 2)->default(1000.00);
            $table->decimal('current_balance', 15, 2)->default(1000.00);
            $table->decimal('target_balance', 15, 2)->nullable();
            $table->enum('status', ['active', 'completed', 'failed'])->default('active');
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->integer('total_trades')->default(0);
            $table->integer('winning_trades')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('paper_trading_sessions');
    }
};
