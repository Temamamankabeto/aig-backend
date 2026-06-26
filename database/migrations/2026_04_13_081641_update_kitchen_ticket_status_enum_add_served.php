<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE kitchen_tickets ALTER COLUMN status TYPE varchar(50)");
        DB::statement("ALTER TABLE kitchen_tickets ALTER COLUMN status SET DEFAULT 'pending'");

        DB::statement("ALTER TABLE kitchen_tickets DROP CONSTRAINT IF EXISTS kitchen_tickets_status_check");

        DB::statement("
            ALTER TABLE kitchen_tickets
            ADD CONSTRAINT kitchen_tickets_status_check
            CHECK (status IN (
                'pending',
                'preparing',
                'ready',
                'served',
                'delayed',
                'rejected'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE kitchen_tickets SET status = 'ready' WHERE status = 'served'");

        DB::statement("ALTER TABLE kitchen_tickets DROP CONSTRAINT IF EXISTS kitchen_tickets_status_check");

        DB::statement("
            ALTER TABLE kitchen_tickets
            ADD CONSTRAINT kitchen_tickets_status_check
            CHECK (status IN (
                'pending',
                'preparing',
                'ready',
                'delayed',
                'rejected'
            ))
        ");

        DB::statement("ALTER TABLE kitchen_tickets ALTER COLUMN status SET DEFAULT 'pending'");
    }
};