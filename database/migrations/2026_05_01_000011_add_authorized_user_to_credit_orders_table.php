<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_orders', function (Blueprint $table) {
            $table->foreignId('credit_account_user_id')->nullable()->after('credit_account_id')->constrained('credit_account_users')->nullOnDelete();
            $table->string('used_by_name')->nullable()->after('credit_account_user_id');
            $table->string('used_by_phone')->nullable()->after('used_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('credit_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_account_user_id');
            $table->dropColumn(['used_by_name', 'used_by_phone']);
        });
    }
};
