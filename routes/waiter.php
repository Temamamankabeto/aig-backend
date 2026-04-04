<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WaiterOrderController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('waiter')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'waiterDashboard']);

    Route::get('/alerts', [NotificationController::class, 'index']);
    Route::get('/alerts/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/alerts/{id}/read', [NotificationController::class, 'markRead']);
    Route::patch('/alerts/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/orders/my', [WaiterOrderController::class, 'myOrders']);
    Route::get('/orders/pending', [WaiterOrderController::class, 'pendingOrders']);
    Route::get('/orders/confirmed', [WaiterOrderController::class, 'confirmedOrders']);
    Route::get('/orders/rejected', [WaiterOrderController::class, 'rejectedOrders']);
    Route::get('/orders/ready', [WaiterOrderController::class, 'readyOrders']);
    Route::get('/orders/served', [WaiterOrderController::class, 'servedOrders']);
    Route::get('/orders/cancelable', [WaiterOrderController::class, 'cancelableOrders']);

    Route::post('/orders/{id}/confirm', [WaiterOrderController::class, 'confirmOrder']);
    Route::post('/orders/{id}/prepare', [WaiterOrderController::class, 'markPreparing']);
    Route::post('/orders/{id}/serve', [WaiterOrderController::class, 'markServed']);
    Route::post('/orders/{id}/request-cancel', [WaiterOrderController::class, 'requestCancel']);

    Route::get('/menu', [WaiterOrderController::class, 'menu']);
    Route::get('/tables', [WaiterOrderController::class, 'tables']);
    Route::post('/orders', [WaiterOrderController::class, 'store']);
});