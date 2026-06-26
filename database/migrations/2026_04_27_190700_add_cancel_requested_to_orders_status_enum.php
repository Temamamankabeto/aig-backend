<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE orders ALTER COLUMN status TYPE varchar(50)");
        DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE orders ALTER COLUMN status SET NOT NULL");

        DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");

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

        DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");

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

        DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE orders ALTER COLUMN status SET NOT NULL");
    }
};