<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // For Antigravity async bridge requests
            $table->string('bridge_request_id')->nullable()->after('ai_model');
            $table->string('bridge_status')->nullable()->after('bridge_request_id'); // pending|processing|completed|failed
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['bridge_request_id', 'bridge_status']);
        });
    }
};
