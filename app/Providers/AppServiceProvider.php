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
    // This is correct and necessary for older MySQL/MariaDB versions
    Schema::defaultStringLength(191);
    
    // You don't need to call both Schema:: and \Illuminate\Database\Schema\Builder::
    // Schema::defaultStringLength(191) handles it globally.
    
    /* REMOVE THIS BLOCK:
       It causes the "max_prepared_stmt_count" error.
       
       if (config('database.default') === 'mysql') {
           \DB::statement('SET SESSION sql_mode = "..."');
       }
    */
}
}
