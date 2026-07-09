<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE purchase_orders SET status = 'food_validated' WHERE status = 'fb_validated'");

        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft'");

        try {
            DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE purchase_orders
            ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN (
                'draft',
                'submitted',
                'food_validated',
                'validation_rejected',
                'approved',
                'partially_received',
                'completed',
                'cancelled'
            ))
        ");

        foreach (['purchase_order_items', 'stock_receiving_items'] as $table) {
            DB::statement("UPDATE {$table} SET base_unit = 'kg' WHERE base_unit IN ('g', 'gram', 'grams')");
            DB::statement("UPDATE {$table} SET base_unit = 'L' WHERE base_unit IN ('ml', 'l', 'liter', 'litre')");
            DB::statement("UPDATE {$table} SET base_unit = 'pcs' WHERE base_unit IN ('pc', 'piece', 'pieces')");
            DB::statement("UPDATE {$table} SET base_unit = 'pcs' WHERE base_unit IS NULL OR base_unit NOT IN ('kg', 'L', 'pcs')");
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN base_unit VARCHAR(20)");

            try {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_base_unit_check");
            } catch (\Throwable $e) {
                // Constraint didn't exist — nothing to drop.
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE purchase_orders SET status = 'fb_validated' WHERE status = 'food_validated'");

        try {
            DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE purchase_orders
            ADD CONSTRAINT purchase_orders_status_check
            CHECK (status IN (
                'draft',
                'submitted',
                'fb_validated',
                'validation_rejected',
                'approved',
                'partially_received',
                'completed',
                'cancelled'
            ))
        ");

        foreach (['purchase_order_items', 'stock_receiving_items'] as $table) {
            try {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_base_unit_check");
            } catch (\Throwable $e) {
                // Constraint didn't exist — nothing to drop.
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
        }
    }
};
