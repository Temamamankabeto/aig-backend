<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_items', 'unit')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('inventory_items', 'unit')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->string('unit', 50)->nullable();
            });
        }
    }
};