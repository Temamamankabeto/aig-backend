<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\DiningTableController;
use Illuminate\Support\Facades\Route;



Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/manager/dashboard', [DashboardController::class, 'managerDashboard']);
    Route::get('/manager/reports/sales-analytics', [AnalyticsReportController::class, 'salesAnalytics']);
    Route::get('/manager/reports/item-popularity', [AnalyticsReportController::class, 'itemPopularity']);
    Route::get('/manager/reports/shift-reconciliation', [AnalyticsReportController::class, 'shiftReconciliationSummary']);
    Route::get('/manager/reports/payment-method-summary', [AnalyticsReportController::class, 'paymentMethodSummary']);
    Route::get('/manager/reports/cashier-performance', [AnalyticsReportController::class, 'cashierPerformance']);
    Route::get('/manager/reports/refund-summary', [AnalyticsReportController::class, 'refundSummary']);
    Route::get('/manager/reports/category-sales', [AnalyticsReportController::class, 'categorySales']);
    Route::get('/manager/reports/recipe-integrity', [InventoryReportController::class, 'recipeIntegrity']);
    Route::get('/manager/reports/stock-valuation', [InventoryReportController::class, 'stockValuation']);


    Route::get('/manager/tables', [DiningTableController::class, 'index']);
    Route::post('/manager/tables', [DiningTableController::class, 'store']);
    Route::put('/manager/tables/{id}', [DiningTableController::class, 'update']);
    Route::post('/manager/tables/{id}/assign', [DiningTableController::class, 'assignWaiter']);
    Route::post('/manager/tables/{id}/transfer', [DiningTableController::class, 'transfer']);
    Route::patch('/manager/tables/{id}/status', [DiningTableController::class, 'setStatus']);
    Route::patch('/manager/tables/{id}/toggle', [DiningTableController::class, 'toggleActive']);
});
