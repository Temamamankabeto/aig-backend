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
        // PostgreSQL UTF-8 encoding is normally configured at database creation.
        // This ensures standard string behavior for this session.
        DB::statement("SET standard_conforming_strings = on");

        // Optional: set timezone for DB session
        DB::statement("SET timezone = 'Africa/Addis_Ababa'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed for session configuration
    }
};