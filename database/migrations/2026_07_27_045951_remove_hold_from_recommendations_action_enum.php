<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // HOLD was never a reachable value — the AI prompt only ever offers BUY/SELL/WAIT —
        // and no existing row uses it. Drop it so the enum matches what the system actually produces.
        DB::statement("ALTER TABLE recommendations MODIFY COLUMN action ENUM('BUY','SELL','WAIT') NOT NULL");
    }

    public function down(): void {
        DB::statement("ALTER TABLE recommendations MODIFY COLUMN action ENUM('BUY','SELL','HOLD','WAIT') NOT NULL");
    }
};
