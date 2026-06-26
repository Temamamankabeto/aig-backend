<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE dining_tables ALTER COLUMN status TYPE varchar(50)");
        DB::statement("ALTER TABLE dining_tables ALTER COLUMN status SET DEFAULT 'available'");
        DB::statement("ALTER TABLE dining_tables ALTER COLUMN status SET NOT NULL");
        DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT IF EXISTS dining_tables_status_check");
        DB::statement("\n            ALTER TABLE dining_tables\n            ADD CONSTRAINT dining_tables_status_check\n            CHECK (status IN ('available', 'occupied', 'reserved', 'cleaning', 'out_of_service'))\n        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE dining_tables SET status = 'cleaning' WHERE status = 'out_of_service'");
        DB::statement("ALTER TABLE dining_tables DROP CONSTRAINT IF EXISTS dining_tables_status_check");
        DB::statement("\n            ALTER TABLE dining_tables\n            ADD CONSTRAINT dining_tables_status_check\n            CHECK (status IN ('available', 'occupied', 'reserved', 'cleaning'))\n        ");
        DB::statement("ALTER TABLE dining_tables ALTER COLUMN status SET DEFAULT 'available'");
    }
};
