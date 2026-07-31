<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->enum('close_target', ['tp1', 'tp2', 'tp3'])->default('tp3')->after('sl');
            $table->json('history')->nullable()->after('close_target');
            $table->string('highest_target_hit')->nullable()->after('history');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropColumn(['close_target', 'history', 'highest_target_hit']);
        });
    }
};
