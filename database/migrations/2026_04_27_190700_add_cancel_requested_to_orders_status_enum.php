<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");

        try {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT orders_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_check
            CHECK (status IN (
                'pending',
                'confirmed',
                'out_for_delivery',
                'in_progress',
                'ready',
                'delivered',
                'served',
                'completed',
                'cancel_requested',
                'cancelled'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE orders SET status = 'cancelled' WHERE status = 'cancel_requested'");

        try {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT orders_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_check
            CHECK (status IN (
                'pending',
                'confirmed',
                'out_for_delivery',
                'in_progress',
                'ready',
                'delivered',
                'served',
                'completed',
                'cancelled'
            ))
        ");

        DB::statement("ALTER TABLE orders MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }
};
