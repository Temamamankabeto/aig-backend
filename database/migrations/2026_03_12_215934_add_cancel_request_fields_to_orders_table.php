<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('completed_at');
            $table->foreignId('cancel_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_request_reason')->nullable()->after('cancel_requested_by');

            $table->timestamp('voided_at')->nullable()->after('cancel_requested_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropConstrainedForeignId('voided_by');

            $table->dropColumn([
                'cancel_requested_at',
                'cancel_request_reason',
                'voided_at',
                'void_reason',
            ]);
        });
    }
};