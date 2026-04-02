<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('table_number')->unique();   // e.g. T1, 01
            $table->unsignedInteger('capacity')->default(4);
            $table->string('section')->nullable();      // indoor/outdoor/vip
            $table->enum('status', ['available', 'occupied', 'reserved', 'cleaning'])->default('available');

            // waiter assignment (staff user)
            $table->foreignId('assigned_waiter_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};