<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/finance/dashboard', [DashboardController::class, 'financeDashboard']);
    Route::get('/finance/reports/sales-analytics', [AnalyticsReportController::class, 'salesAnalytics']);
    Route::get('/finance/reports/item-popularity', [AnalyticsReportController::class, 'itemPopularity']);
    Route::get('/finance/reports/shift-reconciliation', [AnalyticsReportController::class, 'shiftReconciliationSummary']);
    Route::get('/finance/reports/payment-method-summary', [AnalyticsReportController::class, 'paymentMethodSummary']);
    Route::get('/finance/reports/cashier-performance', [AnalyticsReportController::class, 'cashierPerformance']);
    Route::get('/finance/reports/refund-summary', [AnalyticsReportController::class, 'refundSummary']);
    Route::get('/finance/reports/category-sales', [AnalyticsReportController::class, 'categorySales']);
    Route::get('/finance/reports/recipe-integrity', [InventoryReportController::class, 'recipeIntegrity']);
    Route::get('/finance/reports/stock-valuation', [InventoryReportController::class, 'stockValuation']);
});
