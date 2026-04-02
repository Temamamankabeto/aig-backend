<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_receivings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->unique()->constrained('purchase_orders')->cascadeOnDelete();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();   // storekeeper / controller
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // manager/controller
            $table->text('note')->nullable();

            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receivings');
    }
};