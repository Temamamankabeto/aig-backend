<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_items', 'base_unit')) {
                $table->string('base_unit', 10)->default('pc');
            }
        });

        if (Schema::hasColumn('inventory_items', 'unit')) {
            DB::statement("
                UPDATE inventory_items
                SET base_unit = CASE LOWER(COALESCE(unit, ''))
                    WHEN 'kg' THEN 'g'
                    WHEN 'g' THEN 'g'
                    WHEN 'gram' THEN 'g'
                    WHEN 'grams' THEN 'g'
                    WHEN 'liter' THEN 'ml'
                    WHEN 'litre' THEN 'ml'
                    WHEN 'l' THEN 'ml'
                    WHEN 'ml' THEN 'ml'
                    WHEN 'pcs' THEN 'pc'
                    WHEN 'piece' THEN 'pc'
                    WHEN 'pieces' THEN 'pc'
                    WHEN 'pc' THEN 'pc'
                    ELSE 'pc'
                END
            ");
        }

        Schema::table('recipe_items', function (Blueprint $table) {
            if (!Schema::hasColumn('recipe_items', 'base_unit')) {
                $table->string('base_unit', 10)->default('pc');
            }
        });

        if (
            Schema::hasTable('recipe_items') &&
            Schema::hasTable('inventory_items') &&
            Schema::hasColumn('recipe_items', 'inventory_item_id')
        ) {
            DB::statement("
                UPDATE recipe_items ri
                SET base_unit = ii.base_unit
                FROM inventory_items ii
                WHERE ii.id = ri.inventory_item_id
            ");
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_order_items', 'base_unit')) {
                $table->string('base_unit', 10)->default('pc');
            }
        });

        if (
            Schema::hasTable('purchase_order_items') &&
            Schema::hasTable('inventory_items') &&
            Schema::hasColumn('purchase_order_items', 'inventory_item_id')
        ) {
            DB::statement("
                UPDATE purchase_order_items poi
                SET base_unit = ii.base_unit
                FROM inventory_items ii
                WHERE ii.id = poi.inventory_item_id
            ");
        }

        Schema::table('stock_receiving_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_receiving_items', 'base_unit')) {
                $table->string('base_unit', 10)->default('pc');
            }
        });

        if (
            Schema::hasTable('stock_receiving_items') &&
            Schema::hasTable('inventory_items') &&
            Schema::hasColumn('stock_receiving_items', 'inventory_item_id')
        ) {
            DB::statement("
                UPDATE stock_receiving_items sri
                SET base_unit = ii.base_unit
                FROM inventory_items ii
                WHERE ii.id = sri.inventory_item_id
            ");
        }

        foreach (['inventory_items', 'recipe_items', 'purchase_order_items', 'stock_receiving_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'base_unit')) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_base_unit_check");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_base_unit_check CHECK (base_unit IN ('g', 'ml', 'pc'))");
            }
        }

        Schema::dropIfExists('inventory_unit_conversions');
    }

    public function down(): void
    {
        foreach (['stock_receiving_items', 'purchase_order_items', 'recipe_items', 'inventory_items'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'base_unit')) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_base_unit_check");

                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('base_unit');
                });
            }
        }
    }
};