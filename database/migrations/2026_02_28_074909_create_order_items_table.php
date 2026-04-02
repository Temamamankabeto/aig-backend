<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items');

            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2)->default(0);

            // separate processing station
            $table->enum('station', ['kitchen', 'bar'])->default('kitchen');

            // per-item status (independent)
            $table->enum('item_status', [
                'pending', 'preparing', 'ready', 'served', 'cancelled', 'delayed', 'rejected'
            ])->default('pending');

            $table->text('notes')->nullable();
            $table->json('modifiers')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();

            $table->timestamps();

            $table->index(['station', 'item_status']);
            $table->index(['order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};