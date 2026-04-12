<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\BarTicketController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/bar/dashboard', [DashboardController::class, 'barDashboard']);
    Route::get('/bar/alerts', [NotificationController::class, 'index']);
    Route::get('/bar/tickets', [BarTicketController::class, 'index']);
    Route::post('/bar/tickets/{id}/accept', [BarTicketController::class, 'accept']);
    Route::post('/bar/tickets/{id}/ready', [BarTicketController::class, 'ready']);
    Route::post('/bar/tickets/{id}/delay', [BarTicketController::class, 'delay']);
    Route::post('/bar/tickets/{id}/reject', [BarTicketController::class, 'reject']);
    Route::post('/bar/tickets/{id}/served', [BarTicketController::class, 'served']);
});
