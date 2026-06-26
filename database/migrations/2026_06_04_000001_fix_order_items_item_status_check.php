<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'item_status')) {
            return;
        }

        DB::statement("ALTER TABLE order_items ALTER COLUMN item_status TYPE varchar(50)");
        DB::statement("ALTER TABLE order_items ALTER COLUMN item_status SET DEFAULT 'pending'");
        DB::statement("UPDATE order_items SET item_status = 'pending' WHERE item_status IS NULL OR item_status = ''");
        DB::statement("UPDATE order_items SET item_status = 'pending' WHERE item_status NOT IN ('pending','confirmed','preparing','ready','served','completed','cancelled','rejected')");

        DB::statement("ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_item_status_check");
        DB::statement("
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_item_status_check
            CHECK (item_status IN (
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'served',
                'completed',
                'cancelled',
                'rejected'
            ))
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'item_status')) {
            return;
        }

        DB::statement("UPDATE order_items SET item_status = 'pending' WHERE item_status = 'confirmed'");
        DB::statement("ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_item_status_check");
        DB::statement("
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_item_status_check
            CHECK (item_status IN (
                'pending',
                'preparing',
                'ready',
                'served',
                'completed',
                'cancelled',
                'rejected'
            ))
        ");
    }
};
