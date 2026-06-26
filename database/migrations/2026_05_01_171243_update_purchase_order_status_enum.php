<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_orders ALTER COLUMN status TYPE varchar(50)");
        DB::statement("ALTER TABLE purchase_orders ALTER COLUMN status SET DEFAULT 'draft'");

        DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_status_check");

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
        DB::statement("ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_status_check");
        DB::statement("ALTER TABLE purchase_orders ALTER COLUMN status SET DEFAULT 'draft'");
    }
};