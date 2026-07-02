<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cashier_shift_reports')) {
            Schema::create('cashier_shift_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cash_shift_id')->constrained('cash_shifts')->cascadeOnDelete();
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('report_type', 20); // x_report, z_report
                $table->json('payload');
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamps();

                $table->index(['cash_shift_id', 'report_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_reports');
    }
};
