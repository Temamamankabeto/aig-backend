<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE bar_tickets MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");

        try {
            DB::statement("ALTER TABLE bar_tickets DROP CONSTRAINT bar_tickets_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE bar_tickets
            ADD CONSTRAINT bar_tickets_status_check
            CHECK (status IN (
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'served',
                'delayed',
                'rejected'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE bar_tickets SET status = 'ready' WHERE status = 'served'");

        try {
            DB::statement("ALTER TABLE bar_tickets DROP CONSTRAINT bar_tickets_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE bar_tickets
            ADD CONSTRAINT bar_tickets_status_check
            CHECK (status IN (
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'delayed',
                'rejected'
            ))
        ");

        DB::statement("ALTER TABLE bar_tickets MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
    }
};
