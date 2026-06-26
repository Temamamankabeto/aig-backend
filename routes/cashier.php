<?php

use App\Http\Controllers\Api\CashierOrderController;
use App\Http\Controllers\Api\BillController as CashierBillController;
use App\Http\Controllers\Api\PaymentController as CashierPaymentController;
use App\Http\Controllers\Api\RefundRequestController as CashierRefundController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\CashShiftMovementController;
use App\Http\Controllers\Api\CashierReportController;
// use App\Http\Controllers\Api\BillController as CashierReportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get('/cashier/dashboard', [DashboardController::class, 'cashierDashboard']);

    /*
    |--------------------------------------------------------------------------
    | Order creation (POS)
    |--------------------------------------------------------------------------
    */
    Route::get('/cashier/orders/menu', [CashierOrderController::class, 'menu']);
    Route::get('/cashier/orders/tables', [CashierOrderController::class, 'tables']);
    Route::get('/cashier/orders', [CashierOrderController::class, 'index']);
    Route::get('/cashier/orders/{id}', [CashierOrderController::class, 'show']);
    Route::post('/cashier/orders', [CashierOrderController::class, 'cashierStore']);
    Route::post('/cashier/orders/{id}/confirm', [CashierOrderController::class, 'confirm']);

    /*
    |--------------------------------------------------------------------------
    | Bills
    |--------------------------------------------------------------------------
    */
    Route::get('/cashier/bills', [CashierBillController::class, 'index']);
    Route::get('/cashier/bills/{id}', [CashierBillController::class, 'show']);
    Route::post('/cashier/bills/{orderId}/issue', [CashierBillController::class, 'issue']);
    Route::post('/cashier/bills/{id}/void', [CashierBillController::class, 'void']);

    // keep only if you really have this controller method
    Route::post('/cashier/bills/{id}/voide', [CashierBillController::class, 'voide']);

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    */
    Route::post('/cashier/bills/{billId}/payments', [CashierPaymentController::class, 'store']);
    Route::get('/cashier/bills/{billId}/payments', [CashierPaymentController::class, 'history']);
    Route::get('/cashier/payments', [CashierPaymentController::class, 'index']);
    Route::get('/cashier/payments/{id}', [CashierPaymentController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    */
    Route::post('/cashier/refunds', [CashierRefundController::class, 'request']);
    Route::get('/cashier/refunds', [CashierRefundController::class, 'index']);
    Route::get('/cashier/refunds/{id}', [CashierRefundController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Cash Shifts
    |--------------------------------------------------------------------------
    */
    Route::post('/cashier/shifts/open', [CashShiftController::class, 'open']);
    Route::post('/cashier/shifts/{id}/close', [CashShiftController::class, 'close']);
    Route::get('/cashier/shifts/current', [CashShiftController::class, 'current']);
    Route::get('/cashier/shifts', [CashShiftController::class, 'index']);
    Route::get('/cashier/shifts/{id}', [CashShiftController::class, 'show']);

    /*
    |--------------------------------------------------------------------------
    | Cash Shift Movements
    |--------------------------------------------------------------------------
    */
    Route::get('/cashier/shifts/{shiftId}/movements', [CashShiftMovementController::class, 'index']);
    Route::post('/cashier/shifts/{shiftId}/movements', [CashShiftMovementController::class, 'store']);
    Route::get('/cashier/shifts/{shiftId}/movements/{movementId}', [CashShiftMovementController::class, 'show']);
    Route::delete('/cashier/shifts/{shiftId}/movements/{movementId}', [CashShiftMovementController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Cashier Reports
    |--------------------------------------------------------------------------
    */
    Route::get('/cashier/reports/sales-summary', [CashierReportController::class, 'salesSummary']);
    Route::get('/cashier/reports/payment-method-summary', [CashierReportController::class, 'paymentMethodSummary']);
    Route::get('/cashier/reports/shift-summary', [CashierReportController::class, 'shiftSummary']);
    Route::get('/cashier/reports/cashier-performance', [CashierReportController::class, 'cashierPerformance']);
    Route::get('/cashier/reports/refund-summary', [CashierReportController::class, 'refundSummary']);
    Route::get('/cashier/reports/voided-bills', [CashierReportController::class, 'voidedBills']);
    Route::get('/cashier/reports/pending-payments', [CashierReportController::class, 'pendingPayments']);
    Route::get('/cashier/reports/x-report', [CashierReportController::class, 'xReport']);
    Route::get('/cashier/reports/z-report', [CashierReportController::class, 'zReport']);

    /*
    |--------------------------------------------------------------------------
    | Helper data
    |--------------------------------------------------------------------------
    */
    // Frontend POS calls /api/cashier/waiters-lite. Keep /api/waiters-lite too for backward compatibility.
    Route::get('/cashier/waiters-lite', [UserController::class, 'waitersLite']);
    Route::get('/waiters-lite', [UserController::class, 'waitersLite']);
});