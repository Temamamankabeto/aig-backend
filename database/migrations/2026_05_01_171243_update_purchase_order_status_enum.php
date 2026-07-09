<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
                'fb_validated',
                'validation_rejected',
                'approved',
                'partially_received',
                'completed',
                'cancelled'
            ))
        ");
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'draft'");
    }
};
