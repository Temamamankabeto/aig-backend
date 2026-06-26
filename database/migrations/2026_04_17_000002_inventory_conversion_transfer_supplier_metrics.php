<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'base_unit')) {
                $table->enum('base_unit', ['g', 'ml', 'pc'])->default('pc')->after('quantity');
            }
        });

        Schema::table('stock_receiving_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_receiving_items', 'base_unit')) {
                $table->enum('base_unit', ['g', 'ml', 'pc'])->default('pc')->after('quantity_received');
            }
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            try { DB::statement("ALTER TABLE inventory_transactions MODIFY type ENUM('in','out','adjust','transfer_in','transfer_out') NOT NULL"); } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
    }
};
