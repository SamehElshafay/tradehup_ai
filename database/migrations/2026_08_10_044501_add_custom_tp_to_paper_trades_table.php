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
            $table->decimal('custom_tp_percent', 10, 2)->nullable()->after('sl');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paper_trades', function (Blueprint $table) {
            $table->dropColumn('custom_tp_percent');
        });
    }
};
