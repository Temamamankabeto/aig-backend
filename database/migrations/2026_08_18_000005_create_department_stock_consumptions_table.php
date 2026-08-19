<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('department_stock_consumptions')) {
            Schema::create('department_stock_consumptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_transaction_id')->constrained('inventory_transactions')->restrictOnDelete();
                $table->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
                $table->foreignId('department_id')->constrained()->restrictOnDelete();
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->decimal('quantity', 14, 3);
                $table->text('note')->nullable();
                $table->timestamp('consumed_at');
                $table->timestamps();
                $table->index(['department_id', 'consumed_at'], 'dsc_department_consumed_idx');
                $table->index(['inventory_item_id', 'consumed_at'], 'dsc_item_consumed_idx');
            });
        } elseif (! Schema::hasIndex('department_stock_consumptions', 'dsc_item_consumed_idx')) {
            // MySQL may retain the table after the original migration fails on
            // Laravel's automatically generated index name. Resume safely.
            Schema::table('department_stock_consumptions', function (Blueprint $table) {
                $table->index(['inventory_item_id', 'consumed_at'], 'dsc_item_consumed_idx');
            });
        }

        DB::table('inventory_transactions')
            ->where('reference_type', 'department_stockout')
            ->where('used_quantity', '>', 0)
            ->orderBy('id')
            ->each(function ($issue) {
                DB::table('department_stock_consumptions')->updateOrInsert(
                    [
                        'inventory_transaction_id' => $issue->id,
                        'note' => 'Historical consumption balance migrated from department stock custody.',
                    ],
                    [
                    'inventory_item_id' => $issue->inventory_item_id,
                    'department_id' => $issue->reference_id,
                    'recorded_by' => $issue->responsible_user_id ?: $issue->created_by,
                    'quantity' => $issue->used_quantity,
                    'note' => 'Historical consumption balance migrated from department stock custody.',
                    'consumed_at' => $issue->updated_at ?: $issue->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                    ]
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_stock_consumptions');
    }
};
