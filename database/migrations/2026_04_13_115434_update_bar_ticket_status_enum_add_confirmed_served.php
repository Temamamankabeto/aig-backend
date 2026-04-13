<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE bar_tickets
            MODIFY status ENUM(
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'served',
                'delayed',
                'rejected'
            ) DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE bar_tickets
            MODIFY status ENUM(
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'delayed',
                'rejected'
            ) DEFAULT 'pending'
        ");
    }
};