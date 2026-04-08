<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Comprehensive MySQL configuration fixes
        Schema::defaultStringLength(191);
        
        // Set default string length for all database connections
        \Illuminate\Database\Schema\Builder::defaultStringLength(191);
        
        // Configure MySQL for utf8mb4 compatibility
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION sql_mode = "STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO"');
        }
    }
}
