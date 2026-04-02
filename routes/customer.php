<?php

use App\Http\Controllers\Api\CustomerDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customer/dashboard', [CustomerDashboardController::class, 'index']);
    Route::post('/customer/profile', [CustomerDashboardController::class, 'updateProfile']);
});
