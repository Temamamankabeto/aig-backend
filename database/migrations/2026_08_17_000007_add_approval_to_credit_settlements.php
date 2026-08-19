<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_settlements', function (Blueprint $table) {
            $table->string('status', 30)->default('pending_approval')->after('notes')->index();
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');
        });

        // Historical settlements already changed balances before this approval workflow existed.
        DB::table('credit_settlements')->update([
            'status' => 'approved',
            'approved_at' => DB::raw('COALESCE(settled_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('credit_settlements', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['status', 'approved_by', 'approved_at', 'approval_note']);
        });
    }
};
