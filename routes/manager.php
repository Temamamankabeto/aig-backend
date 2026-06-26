<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\DiningTableController;
use Illuminate\Support\Facades\Route;
use App\Models\User;



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


    // Manager owns table management. Admin sidebar access is removed from the frontend.
    Route::get('/manager/tables', [DiningTableController::class, 'index']);
    Route::get('/manager/tables-summary', [DiningTableController::class, 'summary']);
    Route::get('/manager/tables-sections', [DiningTableController::class, 'sections']);
    Route::get('/manager/tables/{id}', [DiningTableController::class, 'show']);
    Route::post('/manager/tables', [DiningTableController::class, 'store']);
    Route::put('/manager/tables/{id}', [DiningTableController::class, 'update']);
    Route::delete('/manager/tables/{id}', [DiningTableController::class, 'destroy']);
    Route::post('/manager/tables/{id}/assign', [DiningTableController::class, 'assignWaiter']);
    Route::delete('/manager/tables/{id}/assign', [DiningTableController::class, 'unassignWaiter']);
    Route::post('/manager/tables/{id}/transfer', [DiningTableController::class, 'transfer']);
    Route::post('/manager/tables/{id}/transfer-orders', [DiningTableController::class, 'transferOrders']);
    Route::get('/manager/tables/{id}/history', [DiningTableController::class, 'history']);
    Route::patch('/manager/tables/{id}/status', [DiningTableController::class, 'setStatus']);
    Route::patch('/manager/tables/{id}/toggle', [DiningTableController::class, 'toggleActive']);

    Route::get('/manager/users/waiters-lite', function (Illuminate\Http\Request $request) {
        $search = trim((string) $request->get('search', ''));

        $users = User::query()
            ->select('id', 'name', 'email', 'phone')
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['Waiter', 'waiter']))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Waiters fetched successfully.',
            'data' => $users,
            'meta' => ['total' => $users->count()],
        ]);
    });
});
