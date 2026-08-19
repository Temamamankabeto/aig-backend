<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('custody_status', 30)->nullable()->after('responsible_user_id')->index();
            $table->timestamp('received_at')->nullable()->after('custody_status');
            $table->decimal('used_quantity', 15, 3)->default(0)->after('received_at');
            $table->decimal('return_requested_quantity', 15, 3)->default(0)->after('used_quantity');
            $table->text('return_request_reason')->nullable()->after('return_requested_quantity');
            $table->timestamp('return_requested_at')->nullable()->after('return_request_reason');
            $table->index(['responsible_user_id', 'reference_type'], 'inventory_tx_responsible_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex('inventory_tx_responsible_reference_idx');
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn(['custody_status', 'received_at', 'used_quantity', 'return_requested_quantity', 'return_request_reason', 'return_requested_at']);
        });
    }
};
