<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Finance|General Admin'])->group(function () {
    Route::get('/finance/dashboard', [DashboardController::class, 'financeDashboard'])->middleware('permission:finance.dashboard');
    Route::get('/finance/reports/sales-analytics', [AnalyticsReportController::class, 'salesAnalytics']);
    Route::get('/finance/reports/item-popularity', [AnalyticsReportController::class, 'itemPopularity']);
    Route::get('/finance/reports/shift-reconciliation', [AnalyticsReportController::class, 'shiftReconciliationSummary']);
    Route::get('/finance/reports/payment-method-summary', [AnalyticsReportController::class, 'paymentMethodSummary']);
    Route::get('/finance/reports/cashier-performance', [AnalyticsReportController::class, 'cashierPerformance']);
    Route::get('/finance/reports/refund-summary', [AnalyticsReportController::class, 'refundSummary']);
    Route::get('/finance/reports/category-sales', [AnalyticsReportController::class, 'categorySales']);
    Route::get('/finance/reports/recipe-integrity', [InventoryReportController::class, 'recipeIntegrity']);
    Route::get('/finance/reports/stock-valuation', [InventoryReportController::class, 'stockValuation']);
    Route::get('/finance/reports/low-stock', [InventoryReportController::class, 'lowStock']);
    Route::get('/finance/reports/reorder-suggestions', [InventoryReportController::class, 'reorderSuggestions']);
    Route::get('/finance/reports/stock-status-summary', [InventoryReportController::class, 'stockStatusSummary']);
    Route::get('/finance/reports/expired-items', [InventoryReportController::class, 'expiredItems']);
    Route::get('/finance/reports/receiving-history', [InventoryReportController::class, 'receivingHistory']);
});
