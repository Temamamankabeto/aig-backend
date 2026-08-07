<?php

use App\Http\Controllers\Api\CustomerDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Customer|General Admin'])->group(function () {
    Route::get('/customer/dashboard', [CustomerDashboardController::class, 'index'])->middleware('permission:auth.me');
    Route::post('/customer/profile', [CustomerDashboardController::class, 'updateProfile'])->middleware('permission:auth.me');
});
