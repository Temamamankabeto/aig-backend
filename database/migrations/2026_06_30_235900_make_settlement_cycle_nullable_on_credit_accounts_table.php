<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE credit_accounts ALTER COLUMN settlement_cycle DROP NOT NULL");
    }

    public function down(): void
    {
        DB::statement("UPDATE credit_accounts SET settlement_cycle = 'agreement' WHERE settlement_cycle IS NULL");
        DB::statement("ALTER TABLE credit_accounts ALTER COLUMN settlement_cycle SET NOT NULL");
    }
};
