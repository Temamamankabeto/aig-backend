<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id'], 'inventory_tx_reference_lookup');
            $table->index(['inventory_item_id', 'created_at'], 'inventory_tx_stock_card_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex('inventory_tx_reference_lookup');
            $table->dropIndex('inventory_tx_stock_card_lookup');
        });
    }
};
