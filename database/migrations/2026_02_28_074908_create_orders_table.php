<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number')->unique(); // ORD-YYYYMMDD-0001

            $table->enum('order_type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');

            $table->foreignId('table_id')->nullable()->constrained('dining_tables')->nullOnDelete();

            // who created it (customer or waiter user)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete()->nullable();

            // waiter responsible (if any)
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();

            // delivery details (only if delivery)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_address')->nullable();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();

            // high-level order status (items have own statuses)
            $table->enum('status', [
                'pending',        // created but not confirmed
                'confirmed',      // accepted
                'out_for_delivery', // for delivery orders
                'in_progress',    // at least one item preparing
                'ready',          // all items ready
                'delivered',      // for delivery orders
                'served',         // all items served
                'completed',      // paid & closed
                'cancelled'
            ])->default('pending');

            // totals (bill may also store)
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('service_charge', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);

            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('ordered_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('served_at')->nullable();




            $table->timestamps();

            $table->index(['status', 'order_type']);
            $table->index(['ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
