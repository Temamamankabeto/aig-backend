<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("UPDATE bills SET status = 'issued' WHERE status IN ('pending_verification', 'submitted', 'returned')");
        DB::statement("UPDATE bills SET status = 'void' WHERE status IN ('failed', 'cancelled')");
        DB::statement("UPDATE bills SET status = 'draft' WHERE status IS NULL OR status = ''");
        DB::statement("UPDATE bills SET status = 'issued' WHERE status NOT IN ('draft', 'issued', 'partial', 'paid', 'void', 'refunded')");

        if (!Schema::hasColumn('bills', 'paid_amount')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('paid_amount', 10, 2)->default(0);
            });
        }

        if (!Schema::hasColumn('bills', 'balance')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->decimal('balance', 10, 2)->default(0);
            });
        }

        DB::statement("ALTER TABLE bills ALTER COLUMN status TYPE varchar(255)");
        DB::statement("ALTER TABLE bills ALTER COLUMN status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE bills ALTER COLUMN status SET NOT NULL");

        DB::statement("ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_status_check");
        DB::statement("
            ALTER TABLE bills ADD CONSTRAINT bills_status_check
            CHECK (status IN ('draft', 'issued', 'partial', 'paid', 'void', 'refunded'))
        ");

        DB::statement("
            UPDATE bills b
            SET paid_amount = COALESCE(p.paid_sum, 0),
                balance = GREATEST(b.total - COALESCE(p.paid_sum, 0), 0),
                status = CASE
                    WHEN b.status = 'void' THEN 'void'
                    WHEN b.status = 'refunded' THEN 'refunded'
                    WHEN COALESCE(p.paid_sum, 0) >= b.total AND b.total > 0 THEN 'paid'
                    WHEN COALESCE(p.paid_sum, 0) > 0 THEN 'partial'
                    WHEN b.status IN ('draft', 'issued') THEN b.status
                    ELSE 'issued'
                END
            FROM (
                SELECT bill_id, COALESCE(SUM(amount), 0) AS paid_sum
                FROM payments
                WHERE status = 'paid'
                GROUP BY bill_id
            ) p
            WHERE p.bill_id = b.id
        ");

        DB::statement("
            UPDATE bills b
            SET paid_amount = 0,
                balance = GREATEST(b.total, 0)
            WHERE NOT EXISTS (
                SELECT 1 FROM payments p
                WHERE p.bill_id = b.id AND p.status = 'paid'
            )
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE bills SET status = 'void' WHERE status = 'refunded'");
        DB::statement("ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_status_check");
        DB::statement("
            ALTER TABLE bills ADD CONSTRAINT bills_status_check
            CHECK (status IN ('draft', 'issued', 'paid', 'partial', 'void'))
        ");

        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'balance')) {
                $table->dropColumn('balance');
            }
            if (Schema::hasColumn('bills', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};