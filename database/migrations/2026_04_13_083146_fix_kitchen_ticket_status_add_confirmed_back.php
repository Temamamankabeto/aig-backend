<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE kitchen_tickets MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");

        try {
            DB::statement("ALTER TABLE kitchen_tickets DROP CONSTRAINT kitchen_tickets_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE kitchen_tickets
            ADD CONSTRAINT kitchen_tickets_status_check
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
        DB::statement("UPDATE kitchen_tickets SET status = 'pending' WHERE status = 'confirmed'");

        try {
            DB::statement("ALTER TABLE kitchen_tickets DROP CONSTRAINT kitchen_tickets_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE kitchen_tickets
            ADD CONSTRAINT kitchen_tickets_status_check
            CHECK (status IN (
                'pending',
                'preparing',
                'ready',
                'served',
                'delayed',
                'rejected'
            ))
        ");

        DB::statement("ALTER TABLE kitchen_tickets MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
    }
};
