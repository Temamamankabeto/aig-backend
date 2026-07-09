<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Session configuration (sql_mode, time_zone) is already handled by the
        // 0000_00_00_000000_configure_innoDB_utf8mb4 migration. Nothing further
        // needed here — utf8mb4 charset/collation is set at the table/column
        // level in each table's own migration.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed.
    }
};
