<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\BarTicketController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Barman|General Admin'])->group(function () {
    Route::get('/bar/dashboard', [DashboardController::class, 'barDashboard'])->middleware('permission:bar.dashboard');
    Route::get('/bar/alerts', [NotificationController::class, 'index'])->middleware('permission:bar.queue.read');
    Route::get('/bar/tickets', [BarTicketController::class, 'index'])->middleware('permission:bar.queue.read');
    Route::post('/bar/tickets/{id}/accept', [BarTicketController::class, 'accept'])->middleware('permission:bar.queue.update');
    Route::post('/bar/tickets/{id}/ready', [BarTicketController::class, 'ready'])->middleware('permission:bar.queue.update');
    Route::post('/bar/tickets/{id}/served', [BarTicketController::class, 'served'])->middleware('permission:bar.queue.update');
    Route::post('/bar/tickets/{id}/delay', [BarTicketController::class, 'delay'])->middleware('permission:bar.queue.update');
    Route::post('/bar/tickets/{id}/reject', [BarTicketController::class, 'reject'])->middleware('permission:bar.queue.update');
});
