<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WaiterOrderController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Waiter|General Admin'])->prefix('waiter')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'waiterDashboard'])->middleware('permission:dashboard.waiter');

    Route::get('/alerts', [NotificationController::class, 'index'])->middleware('permission:orders.read');
    Route::get('/alerts/unread-count', [NotificationController::class, 'unreadCount'])->middleware('permission:orders.read');
    Route::patch('/alerts/{id}/read', [NotificationController::class, 'markRead'])->middleware('permission:orders.read');
    Route::patch('/alerts/read-all', [NotificationController::class, 'markAllRead'])->middleware('permission:orders.read');

    Route::middleware('permission:orders.read')->group(function () {
        Route::get('/orders/my', [WaiterOrderController::class, 'myOrders']);
        Route::get('/orders/pending', [WaiterOrderController::class, 'pendingOrders']);
        Route::get('/orders/confirmed', [WaiterOrderController::class, 'confirmedOrders']);
        Route::get('/orders/rejected', [WaiterOrderController::class, 'rejectedOrders']);
        Route::get('/orders/ready', [WaiterOrderController::class, 'readyOrders']);
        Route::get('/orders/served', [WaiterOrderController::class, 'servedOrders']);
        Route::get('/orders/cancelable', [WaiterOrderController::class, 'cancelableOrders']);
    });

    Route::post('/orders/{id}/confirm', [WaiterOrderController::class, 'confirmOrder'])->middleware('permission:orders.update');
    Route::post('/orders/{id}/prepare', [WaiterOrderController::class, 'markPreparing'])->middleware('permission:orders.update');
    Route::post('/orders/{id}/serve', [WaiterOrderController::class, 'markServed'])->middleware('permission:orders.update');
    Route::post('/orders/{id}/request-cancel', [WaiterOrderController::class, 'requestCancel'])->middleware('permission:orders.cancel');

    Route::get('/menu', [WaiterOrderController::class, 'menu'])->middleware('permission:menu.read');
    Route::get('/tables', [WaiterOrderController::class, 'tables'])->middleware('permission:tables.read');
    Route::post('/orders', [WaiterOrderController::class, 'store'])->middleware('permission:orders.create');
});
