<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure the connection uses InnoDB + utf8mb4 by default for this session.
        DB::statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        // Set timezone for this DB session (Africa/Addis_Ababa is UTC+3, no DST)
        DB::statement("SET time_zone = '+03:00'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed for session configuration
    }
};
