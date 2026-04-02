<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('refund_requests', 'amount')) {
                $table->decimal('amount', 10, 2)->default(0)->after('status');
            }
            if (!Schema::hasColumn('refund_requests', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            if (Schema::hasColumn('refund_requests', 'processed_at')) {
                $table->dropColumn('processed_at');
            }
            if (Schema::hasColumn('refund_requests', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }
};
