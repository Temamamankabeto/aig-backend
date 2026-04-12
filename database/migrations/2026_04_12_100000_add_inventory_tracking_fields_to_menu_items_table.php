<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            if (! Schema::hasColumn('menu_items', 'inventory_tracking_mode')) {
                $table->enum('inventory_tracking_mode', ['recipe', 'direct', 'none'])
                    ->default('recipe')
                    ->after('has_ingredients');
            }

            if (! Schema::hasColumn('menu_items', 'direct_inventory_item_id')) {
                $table->foreignId('direct_inventory_item_id')
                    ->nullable()
                    ->after('inventory_tracking_mode')
                    ->constrained('inventory_items')
                    ->nullOnDelete();
            }
        });

        DB::table('menu_items')
            ->update([
                'inventory_tracking_mode' => DB::raw("CASE WHEN has_ingredients = 1 THEN 'recipe' ELSE 'none' END")
            ]);
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
