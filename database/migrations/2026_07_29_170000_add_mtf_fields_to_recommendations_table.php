<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->boolean('mtf_mode')->default(false)->after('ai_model');
            $table->string('mtf_timeframes', 30)->nullable()->after('mtf_mode');
            $table->text('invalidation')->nullable()->after('mtf_timeframes');
        });
    }

    public function down(): void {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropColumn(['mtf_mode', 'mtf_timeframes', 'invalidation']);
        });
    }
};
