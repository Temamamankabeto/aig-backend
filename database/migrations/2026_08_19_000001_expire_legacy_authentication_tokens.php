<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens') && Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            DB::table('personal_access_tokens')
                ->whereNull('expires_at')
                ->update(['expires_at' => now()]);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'refresh_token')) {
            DB::table('users')->update([
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Authentication invalidation is intentionally not reversible.
    }
};
