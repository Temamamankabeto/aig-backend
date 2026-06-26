<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'payment_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_type', 30)->default('regular')->after('status');
                $table->string('credit_status', 40)->nullable()->after('payment_type');
                $table->unsignedBigInteger('credit_account_id')->nullable()->after('credit_status');
            });
        }

        if (!Schema::hasColumn('bills', 'bill_number')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('bill_number')->nullable()->unique()->after('id');
            });
        }

        if (!Schema::hasColumn('bills', 'bill_type')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('bill_type', 30)->default('normal')->after('status');
                $table->string('credit_status', 40)->nullable()->after('bill_type');
                $table->timestamp('due_date')->nullable()->after('paid_at');
            });
        }

        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_type', 30)->default('customer');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->boolean('is_credit_enabled')->default(true);
            $table->boolean('requires_approval')->default(false);
            $table->string('settlement_cycle', 30)->default('monthly');
            $table->string('status', 30)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_type', 'status']);
        });

        Schema::create('credit_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('bill_id')->unique()->constrained('bills')->cascadeOnDelete();
            $table->foreignId('credit_account_id')->constrained('credit_accounts')->cascadeOnDelete();
            $table->string('credit_reference')->unique();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2)->default(0);
            $table->string('status', 40)->default('credit_pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_date']);
        });

        Schema::create('credit_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_order_id')->constrained('credit_orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30)->default('cash');
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('settled_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_order_id')->constrained('credit_orders')->cascadeOnDelete();
            $table->string('action', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_approval_logs');
        Schema::dropIfExists('credit_settlements');
        Schema::dropIfExists('credit_orders');
        Schema::dropIfExists('credit_accounts');

        if (Schema::hasColumn('bills', 'bill_type')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn(['bill_type', 'credit_status', 'due_date']);
            });
        }

        if (Schema::hasColumn('orders', 'payment_type')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['payment_type', 'credit_status', 'credit_account_id']);
            });
        }
    }
};
