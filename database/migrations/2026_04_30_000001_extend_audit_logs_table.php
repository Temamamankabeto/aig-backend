<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'actor_id')) {
                $table->unsignedBigInteger('actor_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('audit_logs', 'module')) {
                $table->string('module')->nullable()->after('actor_id');
            }

            if (!Schema::hasColumn('audit_logs', 'target_type')) {
                $table->string('target_type')->nullable()->after('module');
            }

            if (!Schema::hasColumn('audit_logs', 'target_id')) {
                $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            }

            if (!Schema::hasColumn('audit_logs', 'changes')) {
                $table->json('changes')->nullable()->after('after');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (['actor_id', 'module', 'target_type', 'target_id', 'changes'] as $column) {
                if (Schema::hasColumn('audit_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
