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

        DB::statement("ALTER TABLE recipe_items ALTER COLUMN base_unit TYPE varchar(20)");
        DB::statement("UPDATE recipe_items SET base_unit = 'kg' WHERE base_unit = 'g'");
        DB::statement("UPDATE recipe_items SET base_unit = 'L' WHERE base_unit = 'ml'");
        DB::statement("UPDATE recipe_items SET base_unit = 'pcs' WHERE base_unit = 'pc'");
        DB::statement("UPDATE recipe_items SET base_unit = 'pcs' WHERE base_unit IS NULL OR base_unit = '' OR base_unit NOT IN ('kg', 'L', 'pcs')");
        DB::statement("ALTER TABLE recipe_items ALTER COLUMN base_unit SET DEFAULT 'pcs'");

        DB::statement("ALTER TABLE recipe_items DROP CONSTRAINT IF EXISTS recipe_items_base_unit_check");
        DB::statement("ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('recipe_items') || ! Schema::hasColumn('recipe_items', 'base_unit')) {
            return;
        }

        DB::statement("ALTER TABLE recipe_items DROP CONSTRAINT IF EXISTS recipe_items_base_unit_check");
        DB::statement("ALTER TABLE recipe_items ADD CONSTRAINT recipe_items_base_unit_check CHECK (base_unit IN ('kg', 'L', 'pcs'))");
    }
};
