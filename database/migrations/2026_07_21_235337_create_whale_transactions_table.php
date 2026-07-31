<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('whale_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 50);
            $table->string('tx_hash', 100)->nullable();
            $table->string('from_address', 100)->nullable();
            $table->string('to_address', 100)->nullable();
            $table->decimal('amount', 30, 8);
            $table->decimal('amount_usd', 20, 2);
            $table->enum('transaction_type', ['transfer','exchange_deposit','exchange_withdrawal','mint','burn'])->default('transfer');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['coin_id', 'transaction_type']);
            $table->index('occurred_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('whale_transactions');
    }
};
