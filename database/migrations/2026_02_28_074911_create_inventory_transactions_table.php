<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();

            $table->enum('type', ['in', 'out', 'adjust']);
            $table->decimal('quantity', 12, 3); // positive number
            $table->decimal('unit_cost', 10, 2)->nullable();

            $table->string('reference_type')->nullable(); // order, purchase, manual
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};