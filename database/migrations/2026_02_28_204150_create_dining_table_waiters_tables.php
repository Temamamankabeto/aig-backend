<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('dining_table_waiters', function (Blueprint $table) {
      $table->id();

      $table->foreignId('dining_table_id')->constrained('dining_tables')->cascadeOnDelete();
      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

      $table->timestamps();

      $table->unique(['dining_table_id', 'user_id']);
      $table->index(['dining_table_id', 'user_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('dining_table_waiters');
  }
};