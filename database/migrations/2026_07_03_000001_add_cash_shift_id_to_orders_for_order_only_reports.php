<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cash_shift_id')) {
                $table->foreignId('cash_shift_id')
                    ->nullable()
                    ->after('payment_received_by')
                    ->constrained('cash_shifts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cash_shift_id')) {
                $table->dropConstrainedForeignId('cash_shift_id');
            }
        });
    }
};
