<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();

            $table->foreignId('chef_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', ['pending', 'preparing', 'ready', 'delayed', 'rejected'])
                ->default('pending');

            $table->unsignedInteger('estimated_minutes')->nullable();

            $table->text('delay_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('prep_note')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};