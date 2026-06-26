<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items');

            $table->decimal('quantity', 12, 3);
            $table->enum('base_unit', ['g', 'ml', 'pc'])->default('pc');
            $table->decimal('unit_cost', 10, 2);

            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->unique(['purchase_order_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};