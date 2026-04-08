<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Configure MySQL for proper InnoDB utf8mb4 support
        DB::statement('SET SESSION sql_mode = "NO_AUTO_VALUE_ON_ZERO"');
        DB::statement('SET NAMES utf8mb4');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Configure database for InnoDB with proper charset
        DB::statement('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        // Set default engine to InnoDB
        DB::statement('SET DEFAULT_STORAGE_ENGINE=InnoDB');
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed for configuration
    }
};
