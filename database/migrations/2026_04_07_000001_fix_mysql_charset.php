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
        // PostgreSQL encoding/collation is configured when the database is created.
        // Keep only PostgreSQL-safe session settings.
        DB::statement("SET standard_conforming_strings = on");
        DB::statement("SET timezone = 'Africa/Addis_Ababa'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed for session configuration.
    }
};