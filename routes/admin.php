<?php

use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiningTableController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InventoryTransactionController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RefundRequestController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockReceivingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaiterOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/general/dashboard', [DashboardController::class, 'generalDashboard']);

    Route::get('/reports/sales-analytics', [AnalyticsReportController::class, 'salesAnalytics']);
    Route::get('/reports/item-popularity', [AnalyticsReportController::class, 'itemPopularity']);
    Route::get('/reports/shift-reconciliation', [AnalyticsReportController::class, 'shiftReconciliationSummary']);
    Route::get('/reports/payment-method-summary', [AnalyticsReportController::class, 'paymentMethodSummary']);
    Route::get('/reports/cashier-performance', [AnalyticsReportController::class, 'cashierPerformance']);
    Route::get('/reports/refund-summary', [AnalyticsReportController::class, 'refundSummary']);
    Route::get('/reports/category-sales', [AnalyticsReportController::class, 'categorySales']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{id}', [AuditLogController::class, 'show']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/menu/categories', [MenuCategoryController::class, 'index']);
    Route::patch('/menu/categories/{id}/toggle', [MenuCategoryController::class, 'toggle']);
    Route::get('/menu/items', [MenuItemController::class, 'index']);
    Route::get('/menu/items/{id}', [MenuItemController::class, 'show']);
    
    Route::post('/menu/items', [MenuItemController::class, 'store']);
    
    Route::put('/menu/items/{id}', [MenuItemController::class, 'update']);
    Route::patch('/menu/items/{id}', [MenuItemController::class, 'update']);
    
    Route::patch('/menu/items/{id}/toggle', [MenuItemController::class, 'toggleActive']);
    Route::patch('/menu/items/{id}/availability', [MenuItemController::class, 'setAvailability']);
    
    Route::patch('/menu/items/{id}/spatial', [MenuItemController::class, 'setSpatial']);
    Route::patch('/menu/items/{id}/normal', [MenuItemController::class, 'setNormal']);

    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/role-permissions', [RoleController::class, 'permissions']);
    Route::get('/roles/{id}/permissions', [RoleController::class, 'rolePermissions']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::post('/roles/{id}/permissions', [RoleController::class, 'assignPermissions']);

    Route::get('/users/roles-lite', [UserController::class, 'rolesLite']);
    Route::get('/users/waiters-lite', [UserController::class, 'waitersLite']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggle']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/roles', [UserController::class, 'assignRole']);

    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::post('/permissions', [PermissionController::class, 'store']);
    Route::put('/permissions/{id}', [PermissionController::class, 'update']);
    Route::delete('/permissions/{id}', [PermissionController::class, 'destroy']);

    Route::get('/tables', [DiningTableController::class, 'index']);
    Route::get('/tables/{id}', [DiningTableController::class, 'show']);
    Route::post('/tables', [DiningTableController::class, 'store']);
    Route::post('/tables/{id}/assign', [DiningTableController::class, 'assignWaiter']);
    Route::post('/tables/{id}/transfer', [DiningTableController::class, 'transfer']);
    Route::patch('/tables/{id}/status', [DiningTableController::class, 'setStatus']);
    Route::patch('/tables/{id}/toggle', [DiningTableController::class, 'toggleActive']);
    Route::put('/tables/{id}', [DiningTableController::class, 'update']);

    Route::get('/orders/request/cancel', [OrderController::class, 'requestCancel']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/waiter/orders/cancelable', [WaiterOrderController::class, 'cancelableOrders']);
    Route::get('/waiter/orders/confirmed', [WaiterOrderController::class, 'confirmedOrders']);
    Route::get('/waiter/orders/rejected', [WaiterOrderController::class, 'rejectedOrders']);
    Route::get('/waiter/orders/ready', [WaiterOrderController::class, 'readyOrders']);
    Route::get('/waiter/orders/served', [WaiterOrderController::class, 'servedOrders']);
    Route::post('/waiter/payments/submit', [PaymentController::class, 'submitByWaiter']);
    Route::get('/payments/pending-approval', [PaymentController::class, 'pendingApproval']);
    Route::post('/payments/{id}/approve', [PaymentController::class, 'approve']);
    Route::post('/payments/{id}/return', [PaymentController::class, 'returnPayment']);
    Route::post('/payments/{id}/fail', [PaymentController::class, 'fail']);
    Route::get('/waiter/payments/report', [PaymentController::class, 'waiterReport']);
    Route::get('/waiter/reports/sold-items', [WaiterOrderController::class, 'waiterSoldItems']);
    Route::get('/report/categories', [WaiterOrderController::class, 'categories']);

    Route::get('/bills', [BillController::class, 'index']);
    Route::get('/bills/{id}', [BillController::class, 'show']);
    Route::get('/orders/{id}/bill', [BillController::class, 'showByOrder']);
    Route::post('/orders/{id}/bill', [BillController::class, 'createOrUpdateDraft']);
    Route::post('/bills/{id}/issue', [BillController::class, 'issue']);
    Route::post('/bills/{id}/void', [BillController::class, 'void']);

    Route::post('/bills/{id}/payments', [PaymentController::class, 'store']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);

    Route::post('/payments/{id}/refund-requests', [RefundRequestController::class, 'store']);
    Route::post('/refund-requests/{id}/approve', [RefundRequestController::class, 'approve']);
    Route::post('/refund-requests/{id}/reject', [RefundRequestController::class, 'reject']);
    Route::post('/refund-requests/{id}/process', [RefundRequestController::class, 'processRefund']);
    Route::get('/refund-requests', [RefundRequestController::class, 'index']);
    Route::get('/refund-requests/{id}', [RefundRequestController::class, 'show']);

    Route::post('/cash-shifts/open', [CashShiftController::class, 'open']);
    Route::post('/cash-shifts/{id}/close', [CashShiftController::class, 'close']);
    Route::get('/cash-shifts', [CashShiftController::class, 'index']);
    Route::get('/cash-shifts/current', [CashShiftController::class, 'current']);
    Route::get('/cash-shifts/{id}', [CashShiftController::class, 'show']);

    Route::get('/inventory/transactions', [InventoryTransactionController::class, 'index']);
    Route::get('/reports/low-stock', [InventoryReportController::class, 'lowStock']);
    Route::get('/reports/reorder-suggestions', [InventoryReportController::class, 'reorderSuggestions']);
    Route::get('/reports/recipe-integrity', [InventoryReportController::class, 'recipeIntegrity']);
    Route::get('/reports/stock-valuation', [InventoryReportController::class, 'stockValuation']);
    Route::post('/inventory/items', [InventoryItemController::class, 'store']);
    Route::get('/inventory/items', [InventoryItemController::class, 'index']);
    Route::get('/inventory/items/trashed', [InventoryItemController::class, 'trashed']);
    Route::get('/inventory/items/{id}', [InventoryItemController::class, 'show']);
    Route::put('/inventory/items/{id}', [InventoryItemController::class, 'update']);
    Route::delete('/inventory/items/{id}', [InventoryItemController::class, 'destroy']);
    Route::post('/inventory/items/{id}/restore', [InventoryItemController::class, 'restore']);
    Route::delete('/inventory/items/{id}/force', [InventoryItemController::class, 'forceDelete']);
    Route::post('/inventory/items/{id}/adjust', [InventoryTransactionController::class, 'adjust']);
    Route::post('/inventory/items/{id}/waste', [InventoryTransactionController::class, 'waste']);

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::post('/purchase-orders/{id}/receive', [StockReceivingController::class, 'receive']);

    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/menu-item/{menuItemId}', [RecipeController::class, 'showByMenuItem']);
    Route::get('/menu/items/{id}/recipe', [RecipeController::class, 'showByMenuItem']);
    Route::get('/recipes/{id}', [RecipeController::class, 'show']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{id}', [RecipeController::class, 'update']);
    Route::patch('/recipes/{id}', [RecipeController::class, 'update']);

    
});
