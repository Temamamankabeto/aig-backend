<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_account_users', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_account_users', 'pin_hash')) {
                $table->string('pin_hash')->nullable()->after('monthly_limit');
            }

            if (!Schema::hasColumn('credit_account_users', 'pin_enabled')) {
                $table->boolean('pin_enabled')->default(true)->after('pin_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_account_users', function (Blueprint $table) {
            if (Schema::hasColumn('credit_account_users', 'pin_enabled')) {
                $table->dropColumn('pin_enabled');
            }

            if (Schema::hasColumn('credit_account_users', 'pin_hash')) {
                $table->dropColumn('pin_hash');
            }
        });
    }
};
