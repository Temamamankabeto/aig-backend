<?php

use App\Http\Controllers\Api\AnalyticsReportController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiningTableController;
use App\Http\Controllers\Api\InventoryItemController;
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


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/profile/update', [UserController::class, 'updateProfile']);
});
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

    // Menu Categories
    Route::prefix('menu/categories')->group(function () {
        Route::get('/', [MenuCategoryController::class, 'index']);
        Route::get('/{id}', [MenuCategoryController::class, 'show']);
        Route::post('/', [MenuCategoryController::class, 'store']);
        Route::put('/{id}', [MenuCategoryController::class, 'update']);
        Route::patch('/{id}', [MenuCategoryController::class, 'update']);
        Route::patch('/{id}/toggle', [MenuCategoryController::class, 'toggle']);
        Route::delete('/{id}', [MenuCategoryController::class, 'destroy']);
    });

 // Admin menu items
Route::get('/menu/items', [MenuItemController::class, 'index']);
Route::get('/menu/items/{id}', [MenuItemController::class, 'show']);
Route::post('/menu/items', [MenuItemController::class, 'store']);
Route::put('/menu/items/{id}', [MenuItemController::class, 'update']);
Route::patch('/menu/items/{id}', [MenuItemController::class, 'update']);
Route::post('/menu/items/{id}', [MenuItemController::class, 'update']); // optional fallback
Route::delete('/menu/items/{id}', [MenuItemController::class, 'destroy']);

Route::patch('/menu/items/{id}/toggle', [MenuItemController::class, 'toggleActive']);
Route::patch('/menu/items/{id}/availability', [MenuItemController::class, 'setAvailability']);
Route::patch('/menu/items/{id}/spatial', [MenuItemController::class, 'setSpatial']);
Route::patch('/menu/items/{id}/normal', [MenuItemController::class, 'setNormal']);
Route::post('/menu/items/{id}/image', [MenuItemController::class, 'uploadImage']);

    // Roles
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/role-permissions', [RoleController::class, 'permissions']);
    Route::get('/roles/{id}/permissions', [RoleController::class, 'rolePermissions']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::post('/roles/{id}/permissions', [RoleController::class, 'assignPermissions']);

    // Users
    Route::get('/users/roles-lite', [UserController::class, 'rolesLite']);
    Route::get('/users/waiters-lite', [UserController::class, 'waitersLite']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggle']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/roles', [UserController::class, 'assignRole']);

    // Permissions
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::post('/permissions', [PermissionController::class, 'store']);
    Route::put('/permissions/{id}', [PermissionController::class, 'update']);
    Route::delete('/permissions/{id}', [PermissionController::class, 'destroy']);

    // Tables
    Route::get('/tables', [DiningTableController::class, 'index']);
    Route::get('/tables/{id}', [DiningTableController::class, 'show']);
    Route::post('/tables', [DiningTableController::class, 'store']);
    Route::put('/tables/{id}', [DiningTableController::class, 'update']);
    Route::delete('/tables/{id}', [DiningTableController::class, 'destroy']);
    Route::post('/tables/{id}/assign', [DiningTableController::class, 'assignWaiter']);
    Route::post('/tables/{id}/transfer', [DiningTableController::class, 'transfer']);
    Route::post('/tables/{id}/transfer-orders', [DiningTableController::class, 'transferOrders']);
    Route::delete('/tables/{id}/assign', [DiningTableController::class, 'unassignWaiter']);
    Route::get('/tables-summary', [DiningTableController::class, 'summary']);
    Route::get('/tables-sections', [DiningTableController::class, 'sections']);
    Route::get('/tables/{id}/history', [DiningTableController::class, 'history']);
    Route::patch('/tables/{id}/status', [DiningTableController::class, 'setStatus']);
    Route::patch('/tables/{id}/toggle', [DiningTableController::class, 'toggleActive']);

    // Orders / Waiter
    Route::get('/orders/request/cancel', [OrderController::class, 'requestCancel']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);

    Route::get('/waiter/orders/cancelable', [WaiterOrderController::class, 'cancelableOrders']);
    Route::get('/waiter/orders/confirmed', [WaiterOrderController::class, 'confirmedOrders']);
    Route::get('/waiter/orders/rejected', [WaiterOrderController::class, 'rejectedOrders']);
    Route::get('/waiter/orders/ready', [WaiterOrderController::class, 'readyOrders']);
    Route::get('/waiter/orders/served', [WaiterOrderController::class, 'servedOrders']);
    Route::get('/waiter/reports/sold-items', [WaiterOrderController::class, 'waiterSoldItems']);
    Route::get('/report/categories', [WaiterOrderController::class, 'categories']);

    // Payments
    Route::post('/waiter/payments/submit', [PaymentController::class, 'submitByWaiter']);
    Route::get('/payments/pending-approval', [PaymentController::class, 'pendingApproval']);
    Route::post('/payments/{id}/approve', [PaymentController::class, 'approve']);
    Route::post('/payments/{id}/return', [PaymentController::class, 'returnPayment']);
    Route::post('/payments/{id}/fail', [PaymentController::class, 'fail']);
    Route::get('/waiter/payments/report', [PaymentController::class, 'waiterReport']);
    Route::post('/bills/{id}/payments', [PaymentController::class, 'store']);
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);

    // Bills
    Route::get('/bills', [BillController::class, 'index']);
    Route::get('/bills/{id}', [BillController::class, 'show']);
    Route::get('/orders/{id}/bill', [BillController::class, 'showByOrder']);
    Route::post('/orders/{id}/bill', [BillController::class, 'createOrUpdateDraft']);
    Route::post('/bills/{id}/issue', [BillController::class, 'issue']);
    Route::post('/bills/{id}/void', [BillController::class, 'void']);

    // Refund Requests
    Route::post('/payments/{id}/refund-requests', [RefundRequestController::class, 'store']);
    Route::post('/refund-requests/{id}/approve', [RefundRequestController::class, 'approve']);
    Route::post('/refund-requests/{id}/reject', [RefundRequestController::class, 'reject']);
    Route::post('/refund-requests/{id}/process', [RefundRequestController::class, 'processRefund']);
    Route::get('/refund-requests', [RefundRequestController::class, 'index']);
    Route::get('/refund-requests/{id}', [RefundRequestController::class, 'show']);

    // Cash Shifts
    Route::post('/cash-shifts/open', [CashShiftController::class, 'open']);
    Route::post('/cash-shifts/{id}/close', [CashShiftController::class, 'close']);
    Route::get('/cash-shifts', [CashShiftController::class, 'index']);
    Route::get('/cash-shifts/current', [CashShiftController::class, 'current']);
    Route::get('/cash-shifts/{id}', [CashShiftController::class, 'show']);

    // Inventory
    Route::get('/inventory/items', [InventoryItemController::class, 'index']);
    Route::get('/inventory/items/trashed', [InventoryItemController::class, 'trashed']);
    Route::get('/inventory/items/{id}', [InventoryItemController::class, 'show']);
    Route::post('/inventory/items', [InventoryItemController::class, 'store']);
    Route::put('/inventory/items/{id}', [InventoryItemController::class, 'update']);
    Route::patch('/inventory/items/{id}', [InventoryItemController::class, 'update']);
    Route::delete('/inventory/items/{id}', [InventoryItemController::class, 'destroy']);
    Route::post('/inventory/items/{id}/restore', [InventoryItemController::class, 'restore']);
    Route::delete('/inventory/items/{id}/force', [InventoryItemController::class, 'forceDelete']);

    Route::get('/inventory/transactions', [InventoryTransactionController::class, 'index']);
    Route::post('/inventory/items/{id}/adjust', [InventoryTransactionController::class, 'adjust']);
    Route::post('/inventory/items/{id}/waste', [InventoryTransactionController::class, 'waste']);
    Route::post('/inventory/items/{id}/transfer', [InventoryTransactionController::class, 'transfer']);

    // Suppliers
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::get('/suppliers/{id}/performance', [SupplierController::class, 'performance']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);

    // Purchase Orders
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::get('/stock-receivings', [StockReceivingController::class, 'index']);
    Route::get('/stock-receivings/{id}', [StockReceivingController::class, 'show']);
    Route::post('/purchase-orders/{id}/receive', [StockReceivingController::class, 'receive']);

    // Recipes
    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/menu-item/{menuItemId}', [RecipeController::class, 'showByMenuItem']);
    Route::get('/menu/items/{id}/recipe', [RecipeController::class, 'showByMenuItem']);
    Route::get('/recipes/{id}', [RecipeController::class, 'show']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{id}', [RecipeController::class, 'update']);
    Route::patch('/recipes/{id}', [RecipeController::class, 'update']);
});