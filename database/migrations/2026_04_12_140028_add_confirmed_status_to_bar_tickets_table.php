<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE bar_tickets ALTER COLUMN status TYPE varchar(50)");
        DB::statement("ALTER TABLE bar_tickets ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE bar_tickets ALTER COLUMN status SET NOT NULL");

        DB::statement("ALTER TABLE bar_tickets DROP CONSTRAINT IF EXISTS bar_tickets_status_check");

        DB::statement("
            ALTER TABLE bar_tickets
            ADD CONSTRAINT bar_tickets_status_check
            CHECK (status IN (
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'delayed',
                'rejected'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE bar_tickets SET status = 'pending' WHERE status = 'confirmed'");

        DB::statement("ALTER TABLE bar_tickets DROP CONSTRAINT IF EXISTS bar_tickets_status_check");

        DB::statement("
            ALTER TABLE bar_tickets
            ADD CONSTRAINT bar_tickets_status_check
            CHECK (status IN (
                'pending',
                'preparing',
                'ready',
                'delayed',
                'rejected'
            ))
        ");

        DB::statement("ALTER TABLE bar_tickets ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement("ALTER TABLE bar_tickets ALTER COLUMN status SET NOT NULL");
    }
};