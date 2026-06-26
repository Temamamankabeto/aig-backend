<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_account_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_account_id');
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('position')->nullable();
            $table->string('id_number')->nullable();
            $table->decimal('daily_limit', 12, 2)->nullable();
            $table->decimal('monthly_limit', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('credit_account_id')->references('id')->on('credit_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_account_users');
    }
};
