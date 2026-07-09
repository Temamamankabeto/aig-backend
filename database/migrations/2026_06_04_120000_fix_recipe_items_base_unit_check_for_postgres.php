<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipe_items') || ! Schema::hasColumn('recipe_items', 'base_unit')) {
            return;
        }

        DB::statement("UPDATE recipe_items SET base_unit = 'kg' WHERE base_unit = 'g'");
        DB::statement("UPDATE recipe_items SET base_unit = 'L' WHERE base_unit = 'ml'");
        DB::statement("UPDATE recipe_items SET base_unit = 'pcs' WHERE base_unit = 'pc'");
        DB::statement("UPDATE recipe_items SET base_unit = 'pcs' WHERE base_unit IS NULL OR base_unit = '' OR base_unit NOT IN ('kg', 'L', 'pcs')");

        DB::statement("ALTER TABLE recipe_items MODIFY COLUMN base_unit VARCHAR(20) DEFAULT 'pcs'");

        try {
            DB::statement("ALTER TABLE recipe_items DROP CONSTRAINT recipe_items_base_unit_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }
        DB::statement("ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('recipe_items') || ! Schema::hasColumn('recipe_items', 'base_unit')) {
            return;
        }

        try {
            DB::statement("ALTER TABLE recipe_items DROP CONSTRAINT recipe_items_base_unit_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }
        DB::statement("ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
    }
};
