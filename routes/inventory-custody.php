<?php

use App\Http\Controllers\Api\InventoryTransactionController;
use App\Http\Controllers\Api\DepartmentStockoutRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('inventory-custody')->group(function () {
    Route::get('/request-items', [DepartmentStockoutRequestController::class, 'items']);
    Route::get('/stockout-requests', [DepartmentStockoutRequestController::class, 'mine']);
    Route::post('/stockout-requests', [DepartmentStockoutRequestController::class, 'store']);
    Route::get('/consumption-report', [InventoryTransactionController::class, 'departmentConsumptionReport']);
    Route::get('/issues', [InventoryTransactionController::class, 'myDepartmentIssues']);
    Route::post('/issues/{issue}/acknowledge', [InventoryTransactionController::class, 'acknowledgeDepartmentIssue']);
    Route::post('/issues/{issue}/use', [InventoryTransactionController::class, 'recordDepartmentUse']);
    Route::post('/issues/{issue}/request-return', [InventoryTransactionController::class, 'requestDepartmentReturn']);
});
