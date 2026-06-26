<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'sku')) {
                $table->string('sku')->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('inventory_items', 'base_unit')) {
                $table->enum('base_unit', ['g', 'ml', 'pc'])->default('pc')->after('sku');
            }

            if (! Schema::hasColumn('inventory_items', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('average_purchase_price');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'contract_terms')) {
                $table->text('contract_terms')->nullable()->after('credit_days');
            }
            if (! Schema::hasColumn('suppliers', 'delivery_performance_notes')) {
                $table->text('delivery_performance_notes')->nullable()->after('contract_terms');
            }
            if (! Schema::hasColumn('suppliers', 'notes')) {
                $table->text('notes')->nullable()->after('delivery_performance_notes');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'received_quantity')) {
                $table->decimal('received_quantity', 12, 3)->default(0)->after('quantity');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'submitted_by')) {
                $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('received_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'notes')) {
                $table->text('notes')->nullable()->after('expected_date');
            }
            if (! Schema::hasColumn('purchase_orders', 'reference_source')) {
                $table->string('reference_source')->nullable()->after('notes');
            }
        });

        if (! Schema::hasTable('purchase_order_status_histories')) {
            Schema::create('purchase_order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('note')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_receiving_items')) {
            Schema::create('stock_receiving_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_receiving_id')->constrained('stock_receivings')->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
                $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
                $table->decimal('quantity_received', 12, 3);
                $table->decimal('unit_cost', 12, 2)->nullable();
                $table->date('expiry_date')->nullable();
                $table->text('batch_note')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('stock_receivings', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_receivings', 'delivery_note_path')) {
                $table->string('delivery_note_path')->nullable()->after('note');
            }
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            try { DB::table('purchase_orders')->where('status', 'received')->update(['status' => 'completed']); } catch (\Throwable $e) {}
            try { DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','submitted','approved','partially_received','completed','cancelled') NOT NULL DEFAULT 'draft'"); } catch (\Throwable $e) {}
            try { DB::statement("ALTER TABLE stock_receivings DROP INDEX stock_receivings_purchase_order_id_unique"); } catch (\Throwable $e) {}
        }

        if ($driver === 'sqlite') {
            // SQLite test environments may keep the earlier structure. The application logic uses status strings safely.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_receiving_items')) {
            Schema::dropIfExists('stock_receiving_items');
        }
        if (Schema::hasTable('purchase_order_status_histories')) {
            Schema::dropIfExists('purchase_order_status_histories');
        }
        if (Schema::hasTable('inventory_unit_conversions')) {
            Schema::dropIfExists('inventory_unit_conversions');
        }
    }
};
