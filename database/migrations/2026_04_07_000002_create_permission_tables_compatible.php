<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop all tables first
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        
        // Set MySQL configuration for older versions
        DB::statement('SET NAMES utf8');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        // Create permissions table with compatible charset
        DB::statement("
            CREATE TABLE `permissions` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(125) NOT NULL,
                `guard_name` varchar(125) NOT NULL,
                `created_at` timestamp NULL DEFAULT NULL,
                `updated_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ");
        
        // Create roles table
        DB::statement("
            CREATE TABLE `roles` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(125) NOT NULL,
                `guard_name` varchar(125) NOT NULL,
                `created_at` timestamp NULL DEFAULT NULL,
                `updated_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ");
        
        // Create model_has_permissions table
        DB::statement("
            CREATE TABLE `model_has_permissions` (
                `permission_id` bigint unsigned NOT NULL,
                `model_type` varchar(191) NOT NULL,
                `model_id` bigint unsigned NOT NULL,
                PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
                KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
                CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ");
        
        // Create model_has_roles table
        DB::statement("
            CREATE TABLE `model_has_roles` (
                `role_id` bigint unsigned NOT NULL,
                `model_type` varchar(191) NOT NULL,
                `model_id` bigint unsigned NOT NULL,
                PRIMARY KEY (`role_id`,`model_id`,`model_type`),
                KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
                CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ");
        
        // Create role_has_permissions table
        DB::statement("
            CREATE TABLE `role_has_permissions` (
                `permission_id` bigint unsigned NOT NULL,
                `role_id` bigint unsigned NOT NULL,
                PRIMARY KEY (`permission_id`,`role_id`),
                KEY `role_has_permissions_role_id_foreign` (`role_id`),
                CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
        ");
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
