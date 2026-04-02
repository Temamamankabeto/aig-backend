<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();

            $table->enum('method', ['cash','transfer'])->default('cash');
            $table->decimal('amount', 10, 2);

            $table->string('reference')->nullable(); // tx id for mobile/card
                  $table->string('receipt_path')->nullable();


            $table->enum('status', ['submitted', 'returned', 'failed', 'refunded','paid'])->default('submitted');

            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete(); // cashier
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();

            $table->timestamp('paid_at')->useCurrent();

            $table->timestamps();

            $table->index(['method', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};