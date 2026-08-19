<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('bill_id')->nullable()->change();
            $table->foreignId('order_id')->nullable()->after('bill_id')->constrained('orders')->cascadeOnDelete();
            $table->string('finance_status', 30)->default('pending')->after('status')->index();
            $table->string('finance_receipt_path')->nullable()->after('receipt_path');
            $table->foreignId('finance_received_by')->nullable()->after('finance_status')->constrained('users')->nullOnDelete();
            $table->timestamp('finance_received_at')->nullable()->after('finance_received_by');
            $table->text('finance_note')->nullable()->after('finance_received_at');
            $table->index(['order_id', 'finance_status']);
            $table->index(['paid_at', 'finance_status']);
        });

        DB::table('payments')->orderBy('id')->chunkById(200, function ($payments) {
            foreach ($payments as $payment) {
                if (! $payment->bill_id) {
                    continue;
                }
                $orderId = DB::table('bills')->where('id', $payment->bill_id)->value('order_id');
                if ($orderId) {
                    DB::table('payments')->where('id', $payment->id)->update(['order_id' => $orderId]);
                }
            }
        });

        DB::table('orders')
            ->where('payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '!=', 'credit');
            })
            ->whereNotNull('payment_received_by')
            ->orderBy('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    if (DB::table('payments')->where('order_id', $order->id)->exists()) {
                        continue;
                    }
                    DB::table('payments')->insert([
                        'bill_id' => DB::table('bills')->where('order_id', $order->id)->value('id'),
                        'order_id' => $order->id,
                        'method' => $order->payment_method ?: 'cash',
                        'amount' => $order->total,
                        'status' => 'paid',
                        'finance_status' => 'pending',
                        'received_by' => $order->payment_received_by,
                        'cash_shift_id' => null,
                        'paid_at' => $order->paid_at ?: $order->updated_at,
                        'created_at' => $order->paid_at ?: $order->created_at,
                        'updated_at' => $order->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'finance_status']);
            $table->dropIndex(['paid_at', 'finance_status']);
            $table->dropForeign(['finance_received_by']);
            $table->dropForeign(['order_id']);
            $table->dropColumn(['order_id', 'finance_status', 'finance_receipt_path', 'finance_received_by', 'finance_received_at', 'finance_note']);
            // bill_id remains nullable because direct order payments do not require a legacy bill.
        });
    }
};
