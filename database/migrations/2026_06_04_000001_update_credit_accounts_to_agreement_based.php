<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_accounts', 'tin_number')) {
                $table->string('tin_number', 80)->nullable()->after('name');
            }
            if (!Schema::hasColumn('credit_accounts', 'representative_name')) {
                $table->string('representative_name')->nullable()->after('tin_number');
            }
            if (!Schema::hasColumn('credit_accounts', 'representative_phone')) {
                $table->string('representative_phone', 80)->nullable()->after('representative_name');
            }
        });

        DB::statement("UPDATE credit_accounts SET account_type = 'bulky' WHERE account_type IN ('organization', 'staff', 'student')");
        DB::statement("UPDATE credit_accounts SET account_type = 'single' WHERE account_type IN ('customer')");
        DB::statement("UPDATE credit_accounts SET account_type = 'bulky' WHERE account_type IS NULL OR account_type = ''");

        if (!Schema::hasTable('credit_agreements')) {
            Schema::create('credit_agreements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('credit_account_id')->constrained('credit_accounts')->cascadeOnDelete();
                $table->string('meal_type');
                $table->unsignedInteger('number_of_person')->default(1);
                $table->string('single_person_name')->nullable();
                $table->decimal('price_per_person', 12, 2)->default(0);
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('total_price', 14, 2)->default(0);
                $table->string('agreement_letter_path')->nullable();
                $table->string('status', 30)->default('active');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['credit_account_id', 'status', 'start_date', 'end_date'], 'credit_agreements_active_idx');
            });
        }

        if (Schema::hasTable('credit_orders') && !Schema::hasColumn('credit_orders', 'credit_agreement_id')) {
            Schema::table('credit_orders', function (Blueprint $table) {
                $table->foreignId('credit_agreement_id')->nullable()->after('credit_account_user_id')->constrained('credit_agreements')->nullOnDelete();
            });
        }

        try {
            DB::statement("ALTER TABLE credit_agreements DROP CONSTRAINT credit_agreements_status_check");
        } catch (\Throwable $e) {
            // Constraint didn't exist — nothing to drop.
        }
        DB::statement("ALTER TABLE credit_agreements ADD CONSTRAINT credit_agreements_status_check CHECK (status IN ('active', 'disabled', 'expired'))");
    }

    public function down(): void
    {
        if (Schema::hasTable('credit_orders') && Schema::hasColumn('credit_orders', 'credit_agreement_id')) {
            Schema::table('credit_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('credit_agreement_id');
            });
        }

        Schema::dropIfExists('credit_agreements');
    }
};
