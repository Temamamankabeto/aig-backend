<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            if (!Schema::hasColumn('menu_items', 'inventory_tracking_mode')) {
                $table->string('inventory_tracking_mode', 20)
                    ->default('recipe');
            }

            if (!Schema::hasColumn('menu_items', 'direct_inventory_item_id')) {
                $table->foreignId('direct_inventory_item_id')
                    ->nullable()
                    ->constrained('inventory_items')
                    ->nullOnDelete();
            }
        });

        DB::statement("
            UPDATE menu_items
            SET inventory_tracking_mode =
                CASE
                    WHEN has_ingredients IS TRUE THEN 'recipe'
                    ELSE 'none'
                END
        ");

        try {
            DB::statement("ALTER TABLE menu_items DROP CONSTRAINT menu_items_inventory_tracking_mode_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }

        DB::statement("
            ALTER TABLE menu_items
            ADD CONSTRAINT menu_items_inventory_tracking_mode_check
            CHECK (inventory_tracking_mode IN ('recipe', 'direct', 'none'))
        ");
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            if (Schema::hasColumn('menu_items', 'direct_inventory_item_id')) {
                $table->dropConstrainedForeignId('direct_inventory_item_id');
            }

            if (Schema::hasColumn('menu_items', 'inventory_tracking_mode')) {
                $table->dropColumn('inventory_tracking_mode');
            }
        });
    }
};
