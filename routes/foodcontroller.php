<?php

use App\Http\Controllers\Api\FoodControllerController;
use App\Http\Controllers\Api\InventoryItemController;
use App\Http\Controllers\Api\InventoryReportController;
use App\Http\Controllers\Api\InventoryTransactionController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\StockReceivingController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('food-controller')->group(function () {
    Route::get('/dashboard', [FoodControllerController::class, 'dashboard']);

    Route::get('/alerts', [NotificationController::class, 'index']);
    Route::get('/alerts/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/alerts/{id}/read', [NotificationController::class, 'markRead']);
    Route::patch('/alerts/read-all', [NotificationController::class, 'markAllRead']);

    Route::prefix('menu/categories')->group(function () {
        Route::get('/', [MenuCategoryController::class, 'index']);
        Route::get('/{id}', [MenuCategoryController::class, 'show']);
        Route::post('/', [MenuCategoryController::class, 'store']);
        Route::put('/{id}', [MenuCategoryController::class, 'update']);
        Route::patch('/{id}', [MenuCategoryController::class, 'update']);
        Route::patch('/{id}/toggle', [MenuCategoryController::class, 'toggle']);
        Route::delete('/{id}', [MenuCategoryController::class, 'destroy']);
    });

    Route::get('/menu/items', [MenuItemController::class, 'index']);
    Route::get('/menu/items/{id}', [MenuItemController::class, 'show']);
    Route::post('/menu/items', [MenuItemController::class, 'store']);
    Route::put('/menu/items/{id}', [MenuItemController::class, 'update']);
    Route::patch('/menu/items/{id}', [MenuItemController::class, 'update']);
    Route::delete('/menu/items/{id}', [MenuItemController::class, 'destroy']);
    Route::patch('/menu/items/{id}/toggle', [MenuItemController::class, 'toggleActive']);
    Route::patch('/menu/items/{id}/availability', [MenuItemController::class, 'setAvailability']);
    Route::patch('/menu/items/{id}/spatial', [MenuItemController::class, 'setSpatial']);
    Route::patch('/menu/items/{id}/normal', [MenuItemController::class, 'setNormal']);
    Route::post('/menu/items/{id}/image', [MenuItemController::class, 'uploadImage']);

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

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::get('/suppliers/{id}/performance', [SupplierController::class, 'performance']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::patch('/suppliers/{id}', [SupplierController::class, 'update']);

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{id}/validate', [PurchaseOrderController::class, 'validateOrder']);
    Route::post('/purchase-orders/{id}/reject-validation', [PurchaseOrderController::class, 'rejectValidation']);
    Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::get('/stock-receivings', [StockReceivingController::class, 'index']);
    Route::get('/stock-receivings/{id}', [StockReceivingController::class, 'show']);
    Route::post('/purchase-orders/{id}/receive', [StockReceivingController::class, 'receive']);

    Route::get('/recipes', [RecipeController::class, 'index']);
    Route::get('/recipes/menu-item/{menuItemId}', [RecipeController::class, 'showByMenuItem']);
    Route::get('/menu/items/{id}/recipe', [RecipeController::class, 'showByMenuItem']);
    Route::get('/recipes/{id}', [RecipeController::class, 'show']);
    Route::post('/recipes', [RecipeController::class, 'store']);
    Route::put('/recipes/{id}', [RecipeController::class, 'update']);
    Route::patch('/recipes/{id}', [RecipeController::class, 'update']);

    Route::get('/reports/low-stock', [InventoryReportController::class, 'lowStock']);
    Route::get('/reports/reorder-suggestions', [InventoryReportController::class, 'reorderSuggestions']);
    Route::get('/reports/recipe-integrity', [InventoryReportController::class, 'recipeIntegrity']);
    Route::get('/reports/stock-status-summary', [InventoryReportController::class, 'stockStatusSummary']);
    Route::get('/reports/expired-items', [InventoryReportController::class, 'expiredItems']);
    Route::get('/reports/receiving-history', [InventoryReportController::class, 'receivingHistory']);
    Route::get('/reports/stock-valuation', [InventoryReportController::class, 'stockValuation']);
});
