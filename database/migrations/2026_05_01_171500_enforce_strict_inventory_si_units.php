<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $unitColumns = [
        ['table' => 'inventory_items', 'column' => 'base_unit', 'nullable' => false, 'default' => 'pcs'],
        ['table' => 'inventory_items', 'column' => 'unit', 'nullable' => true, 'default' => null],
        ['table' => 'recipe_items', 'column' => 'base_unit', 'nullable' => true, 'default' => null],
        ['table' => 'recipe_items', 'column' => 'unit', 'nullable' => true, 'default' => null],
        ['table' => 'purchase_order_items', 'column' => 'unit', 'nullable' => true, 'default' => null],
        ['table' => 'inventory_transactions', 'column' => 'unit', 'nullable' => true, 'default' => null],
        ['table' => 'inventory_item_batches', 'column' => 'unit', 'nullable' => true, 'default' => null],
        ['table' => 'stock_receiving_items', 'column' => 'unit', 'nullable' => true, 'default' => null],
    ];

    public function up(): void
    {
        foreach ($this->unitColumns as $definition) {
            if (!Schema::hasColumn($definition['table'], $definition['column'])) {
                continue;
            }

            $table = $definition['table'];
            $column = $definition['column'];

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(20)");

            DB::statement("UPDATE {$table} SET {$column} = 'kg' WHERE {$column} = 'g'");
            DB::statement("UPDATE {$table} SET {$column} = 'L' WHERE {$column} = 'ml'");
            DB::statement("UPDATE {$table} SET {$column} = 'pcs' WHERE {$column} = 'pc'");
            DB::statement("UPDATE {$table} SET {$column} = 'pcs' WHERE {$column} IS NOT NULL AND {$column} NOT IN ('kg', 'L', 'pcs')");

            if (!$definition['nullable']) {
                DB::statement("UPDATE {$table} SET {$column} = '{$definition['default']}' WHERE {$column} IS NULL OR {$column} = ''");
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");
            } else {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP NOT NULL");
            }

            if ($definition['default'] !== null) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT '{$definition['default']}'");
            } else {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} DROP DEFAULT");
            }

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ('kg', 'L', 'pcs'))");
        }
    }

    public function down(): void
    {
        foreach ($this->unitColumns as $definition) {
            if (!Schema::hasColumn($definition['table'], $definition['column'])) {
                continue;
            }

            DB::statement("ALTER TABLE {$definition['table']} DROP CONSTRAINT IF EXISTS {$definition['table']}_{$definition['column']}_check");
            DB::statement("ALTER TABLE {$definition['table']} ALTER COLUMN {$definition['column']} TYPE varchar(20)");
        }
    }
};