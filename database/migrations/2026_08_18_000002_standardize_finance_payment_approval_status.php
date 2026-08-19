<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('payments')->where('finance_status', 'received')->update(['finance_status' => 'approved']);
    }

    public function down(): void
    {
        DB::table('payments')->where('finance_status', 'approved')->update(['finance_status' => 'received']);
    }
};
