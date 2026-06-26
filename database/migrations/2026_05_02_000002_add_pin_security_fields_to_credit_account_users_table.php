<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_account_users', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_account_users', 'pin_failed_attempts')) {
                $table->unsignedTinyInteger('pin_failed_attempts')->default(0)->after('pin_enabled');
            }

            if (!Schema::hasColumn('credit_account_users', 'pin_available_at')) {
                $table->timestamp('pin_available_at')->nullable()->after('pin_failed_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_account_users', function (Blueprint $table) {
            if (Schema::hasColumn('credit_account_users', 'pin_available_at')) {
                $table->dropColumn('pin_available_at');
            }

            if (Schema::hasColumn('credit_account_users', 'pin_failed_attempts')) {
                $table->dropColumn('pin_failed_attempts');
            }
        });
    }
};
