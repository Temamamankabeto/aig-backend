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

            DB::statement("UPDATE {$table} SET {$column} = 'kg' WHERE {$column} = 'g'");
            DB::statement("UPDATE {$table} SET {$column} = 'L' WHERE {$column} = 'ml'");
            DB::statement("UPDATE {$table} SET {$column} = 'pcs' WHERE {$column} = 'pc'");
            DB::statement("UPDATE {$table} SET {$column} = 'pcs' WHERE {$column} IS NOT NULL AND {$column} NOT IN ('kg', 'L', 'pcs')");

            if (!$definition['nullable']) {
                DB::statement("UPDATE {$table} SET {$column} = '{$definition['default']}' WHERE {$column} IS NULL OR {$column} = ''");
            }

            // MySQL/MariaDB has no separate "ALTER COLUMN ... TYPE / SET NOT NULL / SET DEFAULT"
            // statements — type, nullability, and default must all be redefined together in a
            // single MODIFY COLUMN statement.
            $nullClause = $definition['nullable'] ? 'NULL' : 'NOT NULL';
            $defaultClause = $definition['default'] !== null ? " DEFAULT '{$definition['default']}'" : '';
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR(20) {$nullClause}{$defaultClause}");

            try {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$column}_check");
            } catch (\Throwable $e) {
                // Constraint didn't exist — nothing to drop.
            }
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ('kg', 'L', 'pcs'))");
        }
    }

    public function down(): void
    {
        foreach ($this->unitColumns as $definition) {
            if (!Schema::hasColumn($definition['table'], $definition['column'])) {
                continue;
            }

            try {
                DB::statement("ALTER TABLE {$definition['table']} DROP CONSTRAINT {$definition['table']}_{$definition['column']}_check");
            } catch (\Throwable $e) {
                // Constraint didn't exist — nothing to drop.
            }
            DB::statement("ALTER TABLE {$definition['table']} MODIFY COLUMN {$definition['column']} VARCHAR(20) NULL");
        }
    }
};
