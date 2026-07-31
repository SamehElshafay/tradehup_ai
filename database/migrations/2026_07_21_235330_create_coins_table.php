<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('coins', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->unique(); // BTCUSDT
            $table->string('name', 100); // Bitcoin
            $table->string('base_asset', 20); // BTC
            $table->string('quote_asset', 20)->default('USDT');
            $table->string('logo_url', 500)->nullable();
            $table->decimal('current_price', 20, 8)->default(0);
            $table->decimal('price_change_24h', 10, 4)->default(0);
            $table->decimal('market_cap', 20, 2)->nullable();
            $table->decimal('volume_24h', 20, 2)->nullable();
            $table->decimal('high_24h', 20, 8)->nullable();
            $table->decimal('low_24h', 20, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('coins');
    }
};
