<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_agreements')) {
            Schema::table('credit_agreements', function (Blueprint $table) {
                if (!Schema::hasColumn('credit_agreements', 'agreement_type')) {
                    $table->string('agreement_type', 30)->default('order_based')->after('meal_type');
                }
            });

            DB::statement("UPDATE credit_agreements SET agreement_type = 'order_based' WHERE agreement_type IS NULL OR agreement_type = ''");

            try {
                DB::statement("ALTER TABLE credit_agreements DROP CONSTRAINT credit_agreements_agreement_type_check");
            } catch (\Throwable $e) {
            }
            DB::statement("ALTER TABLE credit_agreements ADD CONSTRAINT credit_agreements_agreement_type_check CHECK (agreement_type IN ('beef_based', 'order_based'))");

            try {
                DB::statement("ALTER TABLE credit_agreements DROP CONSTRAINT credit_agreements_status_check");
            } catch (\Throwable $e) {
            }
            DB::statement("ALTER TABLE credit_agreements ADD CONSTRAINT credit_agreements_status_check CHECK (status IN ('draft', 'active', 'expired', 'suspended', 'completed', 'disabled'))");
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'credit_agreement_id')) {
                    $table->foreignId('credit_agreement_id')->nullable()->after('credit_account_id')->constrained('credit_agreements')->nullOnDelete();
                }
                if (!Schema::hasColumn('orders', 'credit_agreement_type')) {
                    $table->string('credit_agreement_type', 30)->nullable()->after('credit_agreement_id');
                }
                if (!Schema::hasColumn('orders', 'credit_meal_type')) {
                    $table->string('credit_meal_type', 120)->nullable()->after('credit_agreement_type');
                }
                if (!Schema::hasColumn('orders', 'credit_number_of_person')) {
                    $table->unsignedInteger('credit_number_of_person')->nullable()->after('credit_meal_type');
                }
                if (!Schema::hasColumn('orders', 'customer_tin')) {
                    $table->string('customer_tin', 80)->nullable()->after('customer_name');
                }
                if (!Schema::hasColumn('orders', 'bill_printed_at')) {
                    $table->timestamp('bill_printed_at')->nullable()->after('completed_at');
                }
            });
        }

        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table) {
                if (!Schema::hasColumn('bills', 'customer_name')) {
                    $table->string('customer_name')->nullable()->after('bill_number');
                }
                if (!Schema::hasColumn('bills', 'customer_tin')) {
                    $table->string('customer_tin', 80)->nullable()->after('customer_name');
                }
                if (!Schema::hasColumn('bills', 'payment_method')) {
                    $table->string('payment_method', 40)->nullable()->after('credit_status');
                }
                if (!Schema::hasColumn('bills', 'cash_shift_id')) {
                    $table->foreignId('cash_shift_id')->nullable()->after('payment_method')->constrained('cash_shifts')->nullOnDelete();
                }
                if (!Schema::hasColumn('bills', 'printed_at')) {
                    $table->timestamp('printed_at')->nullable()->after('paid_at');
                }
            });

            try {
                DB::statement("ALTER TABLE bills DROP CONSTRAINT bills_status_check");
            } catch (\Throwable $e) {
            }
            DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('draft', 'issued', 'partial', 'paid', 'void', 'refunded', 'credit'))");
        }

        if (Schema::hasTable('payments')) {
            DB::statement("ALTER TABLE payments MODIFY COLUMN method VARCHAR(40)");

            try {
                DB::statement("ALTER TABLE payments DROP CONSTRAINT payments_method_check");
            } catch (\Throwable $e) {
            }
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'card', 'mobile', 'bank', 'transfer', 'credit'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            try {
                DB::statement("ALTER TABLE payments DROP CONSTRAINT payments_method_check");
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('bills')) {
            try {
                DB::statement("ALTER TABLE bills DROP CONSTRAINT bills_status_check");
            } catch (\Throwable $e) {
            }
        }
    }
};
