<?php

use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('purchaser')
    ->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{id}', [SupplierController::class, 'show']);

        Route::get('/inventory/items', [InventoryItemController::class, 'index']);
        Route::get('/inventory/items/{id}', [InventoryItemController::class, 'show']);

        Route::get('/dashboard', [PurchaseOrderController::class, 'dashboard']);
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
        Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history']);
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit']);
    });
