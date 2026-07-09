<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE dining_tables MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'available'");

        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE dining_tables
            ADD CONSTRAINT dining_tables_status_check
            CHECK (status IN ('available', 'occupied', 'reserved', 'cleaning', 'out_of_service'))
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE dining_tables SET status = 'cleaning' WHERE status = 'out_of_service'");

        try {
            DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT dining_tables_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE dining_tables
            ADD CONSTRAINT dining_tables_status_check
            CHECK (status IN ('available', 'occupied', 'reserved', 'cleaning'))
        ");

        DB::statement("ALTER TABLE dining_tables MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'available'");
    }
};
