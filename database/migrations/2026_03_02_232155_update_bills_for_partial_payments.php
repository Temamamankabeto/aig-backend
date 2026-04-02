<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Normalize any legacy/temporary statuses before tightening the enum.
        DB::statement("UPDATE bills SET status = 'issued' WHERE status IN ('pending_verification', 'submitted', 'returned')");
        DB::statement("UPDATE bills SET status = 'void' WHERE status IN ('failed', 'cancelled')");
        DB::statement("UPDATE bills SET status = 'draft' WHERE status IS NULL OR status = ''");
        DB::statement("UPDATE bills SET status = 'issued' WHERE status NOT IN ('draft', 'issued', 'partial', 'paid', 'void', 'refunded')");

        if (! Schema::hasColumn('bills', 'paid_amount')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('paid_amount', 10, 2)->default(0)->after('total');
            });
        }

        if (! Schema::hasColumn('bills', 'balance')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('balance', 10, 2)->default(0)->after('paid_amount');
            });
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->enum('status', [
                'draft',
                'issued',
                'partial',
                'paid',
                'void',
                'refunded',
            ])->default('draft')->change();
        });

        // Recalculate paid and balance from approved/paid payment rows when possible.
        DB::statement(
            "UPDATE bills b
             LEFT JOIN (
                 SELECT bill_id, COALESCE(SUM(amount), 0) AS paid_sum
                 FROM payments
                 WHERE status = 'paid'
                 GROUP BY bill_id
             ) p ON p.bill_id = b.id
             SET b.paid_amount = COALESCE(p.paid_sum, 0),
                 b.balance = GREATEST(b.total - COALESCE(p.paid_sum, 0), 0),
                 b.status = CASE
                     WHEN b.status = 'void' THEN 'void'
                     WHEN b.status = 'refunded' THEN 'refunded'
                     WHEN COALESCE(p.paid_sum, 0) >= b.total AND b.total > 0 THEN 'paid'
                     WHEN COALESCE(p.paid_sum, 0) > 0 THEN 'partial'
                     WHEN b.status IN ('draft', 'issued') THEN b.status
                     ELSE 'issued'
                 END"
        );
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->enum('status', ['draft', 'issued', 'paid', 'partial', 'void'])->default('draft')->change();
        });

        if (Schema::hasColumn('bills', 'balance')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('balance');
            });
        }

        if (Schema::hasColumn('bills', 'paid_amount')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropColumn('paid_amount');
            });
        }
    }
};
