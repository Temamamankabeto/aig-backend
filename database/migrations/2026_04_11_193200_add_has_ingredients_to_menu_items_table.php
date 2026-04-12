<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {

            /**
             * true  = recipe-based deduction required
             * false = packaged/direct item or service item
             */
            $table->boolean('has_ingredients')
                  ->default(true)
                  ->after('menu_mode');

            $table->index('has_ingredients');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {

            $table->dropIndex(['has_ingredients']);

            $table->dropColumn('has_ingredients');
        });
    }
};