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

            $table->enum('base_unit', ['g', 'ml', 'pc'])->default('pc'); // SI base unit used for all inventory quantities

            $table->decimal('minimum_quantity', 12, 3)->default(0);

            $table->decimal('current_stock', 12, 3)->nullable();

            // FIX: remove space from column name
            $table->decimal('average_purchase_price', 12, 3)->nullable();

            $table->timestamps();

            // ADD THIS
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};