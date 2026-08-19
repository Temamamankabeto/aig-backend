<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\FinancePaymentController;
use App\Http\Controllers\Api\FinanceBillController;
use App\Http\Controllers\Api\FinanceProfitController;
use App\Http\Controllers\Api\RefundRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'role:Finance|General Admin'])->group(function () {
    Route::get('/finance/dashboard', [DashboardController::class, 'financeDashboard'])->middleware('permission:finance.dashboard');
    Route::get('/finance/payments', [FinancePaymentController::class, 'index'])->middleware('permission:payments.read');
    Route::get('/finance/bills', [FinanceBillController::class, 'index'])->middleware('permission:bills.read');
    Route::get('/finance/expenses', [FinanceProfitController::class, 'expenses'])->middleware('permission:reports.financial.read');
    Route::post('/finance/expenses', [FinanceProfitController::class, 'storeExpense'])->middleware('permission:reports.financial.read');
    Route::delete('/finance/expenses/{expense}', [FinanceProfitController::class, 'destroyExpense'])->middleware('permission:reports.financial.read');
    Route::get('/finance/reports/profit', [FinanceProfitController::class, 'profitReport'])->middleware('permission:reports.financial.read');
    Route::post('/finance/payments/{id}/receive', [FinancePaymentController::class, 'markReceived'])->middleware('permission:payments.read');
    Route::post('/finance/payments/approve-bulk', [FinancePaymentController::class, 'approveBulk'])->middleware('permission:payments.read');
    Route::get('/finance/refund-requests', [RefundRequestController::class, 'index'])->middleware('permission:payments.refund.approve');
    Route::get('/finance/refund-requests/{id}', [RefundRequestController::class, 'show'])->middleware('permission:payments.refund.approve');
    Route::post('/finance/refund-requests/{id}/approve', [RefundRequestController::class, 'approve'])->middleware('permission:payments.refund.approve');
    Route::post('/finance/refund-requests/{id}/reject', [RefundRequestController::class, 'reject'])->middleware('permission:payments.refund.approve');
    Route::post('/finance/refund-requests/{id}/process', [RefundRequestController::class, 'processRefund'])->middleware('permission:payments.refund.approve');
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
