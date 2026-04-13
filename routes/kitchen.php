<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\KitchenTicketController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/kitchen/dashboard', [DashboardController::class, 'kitchenDashboard']);
    Route::get('/kitchen/alerts', [NotificationController::class, 'index']);
    Route::get('/kitchen/tickets', [KitchenTicketController::class, 'index']);
    Route::post('/kitchen/tickets/{id}/accept', [KitchenTicketController::class, 'accept']);
    Route::post('/kitchen/tickets/{id}/ready', [KitchenTicketController::class, 'ready']);
    Route::post('/kitchen/tickets/{id}/served', [KitchenTicketController::class, 'served']);
});
