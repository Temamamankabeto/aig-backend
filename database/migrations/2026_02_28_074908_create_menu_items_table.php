<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('menu_categories');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['food', 'drink'])->default('food');
            $table->decimal('price', 10, 2);
            $table->string('image_path')->nullable();
            $table->boolean('is_available')->default(true);

            $table->enum('menu_mode', ['normal', 'spatial'])->default('normal');

            $table->boolean('is_active')->default(true);
            $table->json('modifiers')->nullable();
            $table->unsignedInteger('prep_minutes')->nullable();

            $table->timestamps();

            $table->index(['type', 'is_available', 'is_active']);
            $table->index(['menu_mode', 'is_available', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};