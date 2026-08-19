<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('department_stock_consumptions', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 3)->default(0)->after('quantity');
            $table->decimal('total_cost', 16, 2)->default(0)->after('unit_cost');
            $table->string('approval_status', 20)->default('pending')->after('note');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->uuid('approval_batch')->nullable()->after('approved_at');
            $table->index(['approval_status', 'consumed_at'], 'dsc_status_consumed_idx');
            $table->index('approval_batch', 'dsc_approval_batch_idx');
        });

        DB::table('department_stock_consumptions as consumption')
            ->join('inventory_transactions as issue', 'issue.id', '=', 'consumption.inventory_transaction_id')
            ->update([
                'consumption.unit_cost' => DB::raw('COALESCE(issue.unit_cost, 0)'),
                'consumption.total_cost' => DB::raw('ROUND(consumption.quantity * COALESCE(issue.unit_cost, 0), 2)'),
            ]);

        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->string('category', 100);
            $table->string('description', 500);
            $table->decimal('amount', 16, 2);
            $table->date('expense_date');
            $table->string('reference')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['expense_date', 'category'], 'finance_expense_date_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
        Schema::table('department_stock_consumptions', function (Blueprint $table) {
            $table->dropIndex('dsc_status_consumed_idx');
            $table->dropIndex('dsc_approval_batch_idx');
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['unit_cost', 'total_cost', 'approval_status', 'approved_by', 'approved_at', 'approval_batch']);
        });
    }
};
