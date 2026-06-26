<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_per_person', 12, 2)->default(0);
            $table->unsignedInteger('minimum_people')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->decimal('quantity_per_person', 10, 3)->default(1);
            $table->timestamps();
            $table->unique(['package_id', 'menu_item_id']);
        });

        Schema::create('package_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('package_order_number')->unique();
            $table->string('event_name');
            $table->string('event_type')->nullable();
            $table->unsignedInteger('guest_count')->default(1);
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->string('delivery_location')->nullable();
            $table->string('status', 40)->default('draft');
            $table->string('payment_type', 40)->default('cash');
            $table->string('credit_status', 40)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->decimal('deposit_required', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'event_date']);
            $table->index(['payment_type', 'credit_status']);
        });

        Schema::create('package_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_order_id')->constrained('package_orders')->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('package_order_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_order_id')->unique()->constrained('package_orders')->cascadeOnDelete();
            $table->timestamp('prep_start_time')->nullable();
            $table->timestamp('ready_time')->nullable();
            $table->timestamp('delivery_time')->nullable();
            $table->string('assigned_team')->nullable();
            $table->string('status', 40)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('package_order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_order_id')->constrained('package_orders')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 30)->default('cash');
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_order_payments');
        Schema::dropIfExists('package_order_schedules');
        Schema::dropIfExists('package_order_items');
        Schema::dropIfExists('package_orders');
        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};
