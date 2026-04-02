<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->enum('status', ['requested', 'approved', 'rejected', 'processed'])->default('requested');
            $table->text('reason');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete(); // cashier
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // manager/finance
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};