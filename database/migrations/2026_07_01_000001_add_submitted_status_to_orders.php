<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

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
                'submitted',
                'pending',
                'confirmed',
                'out_for_delivery',
                'in_progress',
                'ready',
                'delivered',
                'served',
                'completed',
                'cancel_requested',
                'cancelled',
                'void'
            ))
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        DB::statement("UPDATE orders SET status = 'pending' WHERE status = 'submitted'");

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
};
