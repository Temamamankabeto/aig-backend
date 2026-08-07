<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\KitchenTicketController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Kitchen Staff|General Admin'])->group(function () {
    Route::get('/kitchen/dashboard', [DashboardController::class, 'kitchenDashboard'])->middleware('permission:kitchen.dashboard');
    Route::get('/kitchen/alerts', [NotificationController::class, 'index'])->middleware('permission:kitchen.queue.read');
    Route::get('/kitchen/tickets', [KitchenTicketController::class, 'index'])->middleware('permission:kitchen.queue.read');
    Route::post('/kitchen/tickets/{id}/accept', [KitchenTicketController::class, 'accept'])->middleware('permission:kitchen.queue.update');
    Route::post('/kitchen/tickets/{id}/ready', [KitchenTicketController::class, 'ready'])->middleware('permission:kitchen.queue.update');
    Route::post('/kitchen/tickets/{id}/served', [KitchenTicketController::class, 'served'])->middleware('permission:kitchen.queue.update');
});
