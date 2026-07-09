<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('menu_items', 'inventory_tracking_mode')) {
            // MySQL/MariaDB DOES support "ALTER COLUMN ... SET DEFAULT" directly
            // (unlike TYPE/SET NOT NULL), so this one needed no rewrite.
            DB::statement("ALTER TABLE menu_items ALTER COLUMN inventory_tracking_mode SET DEFAULT 'none'");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('menu_items', 'inventory_tracking_mode')) {
            DB::statement("ALTER TABLE menu_items ALTER COLUMN inventory_tracking_mode SET DEFAULT 'recipe'");
        }
    }
};
