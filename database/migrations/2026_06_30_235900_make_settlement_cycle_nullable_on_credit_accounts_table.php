<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB has no "ALTER COLUMN ... DROP NOT NULL" statement — the column
        // must be redefined with MODIFY COLUMN using its existing type, so we look that
        // type up first instead of guessing/hardcoding it.
        $column = DB::selectOne("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'credit_accounts'
              AND COLUMN_NAME = 'settlement_cycle'
        ");

        if ($column) {
            DB::statement("ALTER TABLE credit_accounts MODIFY COLUMN settlement_cycle {$column->COLUMN_TYPE} NULL");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE credit_accounts SET settlement_cycle = 'agreement' WHERE settlement_cycle IS NULL");

        $column = DB::selectOne("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'credit_accounts'
              AND COLUMN_NAME = 'settlement_cycle'
        ");

        if ($column) {
            DB::statement("ALTER TABLE credit_accounts MODIFY COLUMN settlement_cycle {$column->COLUMN_TYPE} NOT NULL");
        }
    }
};
