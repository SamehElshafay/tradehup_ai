<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coin_id')->constrained()->cascadeOnDelete();
            $table->enum('timeframe', ['1m','5m','15m','30m','1h','4h','1d','1w']);
            $table->json('raw_data')->nullable();
            $table->json('classical_data')->nullable();
            $table->json('smc_data')->nullable();
            $table->json('harmonic_data')->nullable();
            $table->json('volume_profile_data')->nullable();
            $table->json('chart_overlays')->nullable();
            $table->string('overall_bias', 20)->nullable();
            $table->tinyInteger('overall_confidence')->nullable();
            $table->json('confluences')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->index(['coin_id', 'timeframe']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('analyses');
    }
};
