<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 30)->nullable()->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::table('departments')->insert(array_map(fn ($name) => [
            'name' => $name,
            'code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 8)),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['Kitchen', 'Bar', 'Restaurant', 'Housekeeping', 'Maintenance', 'Finance', 'Management', 'Other']));
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
