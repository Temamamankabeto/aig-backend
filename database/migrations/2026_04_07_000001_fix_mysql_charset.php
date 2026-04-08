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
        // Set MySQL configuration to handle utf8mb4 properly
        DB::statement('SET NAMES utf8mb4');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Configure database for proper utf8mb4 support
        DB::statement('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse as this is a configuration fix
    }
};
