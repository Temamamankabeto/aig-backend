<?php

use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Purchaser|General Admin'])
    ->prefix('purchaser')
    ->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.read');
        Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->middleware('permission:suppliers.read');

        Route::get('/inventory/items', [InventoryItemController::class, 'index'])->middleware('permission:inventory.items.read');
        Route::get('/inventory/items/{id}', [InventoryItemController::class, 'show'])->middleware('permission:inventory.items.read');

        Route::get('/dashboard', [PurchaseOrderController::class, 'dashboard'])->middleware('permission:purchase_orders.read');
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.read');
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_orders.read');
        Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history'])->middleware('permission:purchase_orders.read');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create');
        Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_orders.submit');
    });
