<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('sku')->unique();

            $table->enum('category', ['food','beverage','consumable'])->default('food');
            $table->string('unit');                  // kg, liter, pcs
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->decimal('unit_cost', 10, 2)->default(0);

            $table->date('expiry_date')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active']);
            $table->index(['category','is_active']);

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};