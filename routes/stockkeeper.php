<?php

use App\Http\Controllers\Api\InventoryBatchController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InventoryTransactionController;
use App\Http\Controllers\Api\DepartmentStockoutRequestController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\StockReceivingController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Store Keeper|General Admin'])
    ->prefix('stock-keeper')
    ->group(function () {
        Route::get('/stockout-requests', [DepartmentStockoutRequestController::class, 'issueQueue']);
        Route::post('/stockout-requests/{stockoutRequest}/issue', [DepartmentStockoutRequestController::class, 'issue']);
        Route::get('/inventory/items', [InventoryItemController::class, 'index'])->middleware('permission:inventory.items.read');
        Route::get('/inventory/items/{id}', [InventoryItemController::class, 'show'])->middleware('permission:inventory.items.read');

        Route::get('/inventory/transactions', [InventoryTransactionController::class, 'index'])->middleware('permission:inventory.movements.read');
        Route::get('/inventory/stock-balances', [InventoryTransactionController::class, 'stockBalances'])->middleware('permission:inventory.movements.read');
        Route::get('/inventory/returnable-issues', [InventoryTransactionController::class, 'returnableIssues'])->middleware('permission:inventory.movements.read');
        Route::get('/inventory/items/{id}/stock-card', [InventoryTransactionController::class, 'stockCard'])->middleware('permission:inventory.movements.read');
        Route::post('/inventory/issues/{id}/return', [InventoryTransactionController::class, 'returnToStore'])->middleware('permission:inventory.adjustments.create');
        Route::post('/inventory/items/{id}/adjust', [InventoryTransactionController::class, 'adjust'])->middleware('permission:inventory.adjustments.create');
        Route::post('/inventory/items/{id}/waste', [InventoryTransactionController::class, 'waste'])->middleware('permission:inventory.waste.create');
        Route::post('/inventory/items/{id}/stockout', [InventoryTransactionController::class, 'stockout'])->middleware('permission:inventory.adjustments.create');
        Route::post('/inventory/items/{id}/transfer', [InventoryTransactionController::class, 'transfer'])->middleware('permission:inventory.adjustments.create');

        Route::get('/inventory/batches', [InventoryBatchController::class, 'index'])->middleware('permission:inventory.batches.read');
        Route::get('/reports/low-stock', [InventoryReportController::class, 'lowStock'])->middleware('permission:inventory.items.read');

        Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:inventory.items.read');
        Route::get('/departments/{department}', [DepartmentController::class, 'show'])->middleware('permission:inventory.items.read');
        Route::get('/departments/{department}/users', [InventoryTransactionController::class, 'departmentUsers'])->middleware('permission:inventory.items.read');

        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.read');
        Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->middleware('permission:suppliers.read');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.read');
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_orders.read');
        Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history'])->middleware('permission:purchase_orders.read');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create');
        Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_orders.submit');

        Route::get('/stock-receivings', [StockReceivingController::class, 'index'])->middleware('permission:purchase_orders.read');
        Route::get('/stock-receivings/{id}', [StockReceivingController::class, 'show'])->middleware('permission:purchase_orders.read');
        Route::post('/purchase-orders/{id}/receive', [StockReceivingController::class, 'receive'])->middleware('permission:purchase_orders.receive');
    });
