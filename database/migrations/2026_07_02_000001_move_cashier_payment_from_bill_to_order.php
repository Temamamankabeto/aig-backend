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
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 40)->default('unpaid')->after('payment_type');
            }
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 40)->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('orders', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('orders', 'change_amount')) {
                $table->decimal('change_amount', 12, 2)->default(0)->after('paid_amount');
            }
            if (! Schema::hasColumn('orders', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('orders', 'payment_received_by')) {
                $table->foreignId('payment_received_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            }
        });

        if (Schema::hasTable('orders')) {
            DB::statement("UPDATE orders SET payment_status = CASE WHEN payment_type = 'credit' THEN 'credit_pending' ELSE 'unpaid' END WHERE payment_status IS NULL OR payment_status = ''");
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['payment_received_by', 'paid_at', 'change_amount', 'paid_amount', 'payment_method', 'payment_status'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
