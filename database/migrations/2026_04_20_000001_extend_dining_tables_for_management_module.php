<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            if (! Schema::hasColumn('dining_tables', 'name')) {
                $table->string('name')->nullable()->after('table_number');
            }
            if (! Schema::hasColumn('dining_tables', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('dining_tables', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_public');
            }
        });

        DB::table('dining_tables')->whereNull('name')->get(['id', 'table_number'])->each(function ($row) {
            DB::table('dining_tables')
                ->where('id', $row->id)
                ->update(['name' => 'Table ' . $row->table_number]);
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            if (Schema::hasColumn('dining_tables', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('dining_tables', 'is_public')) {
                $table->dropColumn('is_public');
            }
            if (Schema::hasColumn('dining_tables', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
