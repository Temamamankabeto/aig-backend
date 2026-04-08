<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'inventory_deducted_at')) {
                $table->timestamp('inventory_deducted_at')->nullable()->after('served_at');
            }
        });

        try {
            DB::statement("ALTER TABLE order_items MODIFY COLUMN item_status ENUM('pending','confirmed','preparing','ready','served','cancelled','delayed','rejected') NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
            // Ignore when the database driver does not support this exact ALTER syntax.
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE order_items MODIFY COLUMN item_status ENUM('pending','preparing','ready','served','cancelled','delayed','rejected') NOT NULL DEFAULT 'pending'");
        } catch (\Throwable $e) {
            // Ignore when the database driver does not support this exact ALTER syntax.
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'inventory_deducted_at')) {
                $table->dropColumn('inventory_deducted_at');
            }
        });
    }
};
