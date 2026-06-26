<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('package_orders', 'credit_account_id')) {
                $table->foreignId('credit_account_id')
                    ->nullable()
                    ->constrained('credit_accounts')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('package_orders', 'credit_account_user_id')) {
                $table->unsignedBigInteger('credit_account_user_id')->nullable();
            }

            if (!Schema::hasColumn('package_orders', 'used_by_name')) {
                $table->text('used_by_name')->nullable();
            }

            if (!Schema::hasColumn('package_orders', 'used_by_phone')) {
                $table->text('used_by_phone')->nullable();
            }
        });

        if (
            Schema::hasTable('credit_account_users') &&
            Schema::hasColumn('package_orders', 'credit_account_user_id')
        ) {
            Schema::table('package_orders', function (Blueprint $table) {
                $table->foreign('credit_account_user_id', 'package_orders_credit_account_user_id_fk')
                    ->references('id')
                    ->on('credit_account_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('package_orders', function (Blueprint $table) {
            $table->dropForeign(['credit_account_id']);

            if (Schema::hasTable('credit_account_users')) {
                $table->dropForeign('package_orders_credit_account_user_id_fk');
            }

            $table->dropColumn([
                'credit_account_id',
                'credit_account_user_id',
                'used_by_name',
                'used_by_phone',
            ]);
        });
    }
};