<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'credit_agreement_id')) {
                $table->unsignedBigInteger('credit_agreement_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'credit_order_mode')) {
                $table->string('credit_order_mode', 40)->nullable();
            }
            if (! Schema::hasColumn('orders', 'meal_type')) {
                $table->string('meal_type', 80)->nullable();
            }
            if (! Schema::hasColumn('orders', 'number_of_person')) {
                $table->unsignedInteger('number_of_person')->nullable();
            }
            if (! Schema::hasColumn('orders', 'customer_tin')) {
                $table->string('customer_tin', 80)->nullable();
            }
            if (! Schema::hasColumn('orders', 'bill_printed_at')) {
                $table->timestamp('bill_printed_at')->nullable();
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'customer_name')) {
                $table->string('customer_name')->nullable();
            }
            if (! Schema::hasColumn('bills', 'customer_tin')) {
                $table->string('customer_tin', 80)->nullable();
            }
            if (! Schema::hasColumn('bills', 'payment_method')) {
                $table->string('payment_method', 40)->nullable();
            }
        });

        if (Schema::hasTable('credit_agreements')) {
            Schema::table('credit_agreements', function (Blueprint $table) {
                if (! Schema::hasColumn('credit_agreements', 'agreement_type')) {
                    $table->string('agreement_type', 40)->default('order_based');
                }
            });

            DB::statement("UPDATE credit_agreements SET agreement_type = 'order_based' WHERE agreement_type IS NULL OR agreement_type = ''");
            DB::statement("ALTER TABLE credit_agreements DROP CONSTRAINT IF EXISTS credit_agreements_agreement_type_check");
            DB::statement("ALTER TABLE credit_agreements ADD CONSTRAINT credit_agreements_agreement_type_check CHECK (agreement_type IN ('order_based', 'beef_based'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('credit_agreements')) {
            DB::statement("ALTER TABLE credit_agreements DROP CONSTRAINT IF EXISTS credit_agreements_agreement_type_check");
        }
    }
};
