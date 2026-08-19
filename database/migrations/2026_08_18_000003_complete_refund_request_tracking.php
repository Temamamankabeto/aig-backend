<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->text('decision_note')->nullable()->after('reason');
            $table->string('refund_method', 30)->nullable()->after('decision_note');
            $table->string('refund_reference')->nullable()->after('refund_method');
            $table->string('proof_path')->nullable()->after('refund_reference');
            $table->foreignId('processed_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'requested_at']);
            $table->dropForeign(['processed_by']);
            $table->dropColumn(['decision_note', 'refund_method', 'refund_reference', 'proof_path', 'processed_by']);
        });
    }
};
