<?php

use App\Http\Controllers\Api\CreditOrderController;
use App\Http\Controllers\Api\CreditAccountUserController;
use App\Http\Controllers\Api\CreditCardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/credit/cards/validate', [CreditCardController::class, 'validate']);
    Route::get('/credit/accounts', [CreditOrderController::class, 'accounts']);
    Route::post('/credit/accounts', [CreditOrderController::class, 'storeAccount']);
    Route::get('/credit/accounts/{id}', [CreditOrderController::class, 'showAccount']);
    Route::put('/credit/accounts/{id}', [CreditOrderController::class, 'updateAccount']);
    Route::patch('/credit/accounts/{id}/toggle', [CreditOrderController::class, 'toggleAccount']);
    Route::patch('/credit/accounts/{id}/block', [CreditOrderController::class, 'blockAccount']);
    Route::patch('/credit/accounts/{id}/unblock', [CreditOrderController::class, 'unblockAccount']);

    Route::get('/credit/accounts/{id}/users', [CreditAccountUserController::class, 'index']);
    Route::post('/credit/accounts/{id}/users', [CreditAccountUserController::class, 'store']);
    Route::put('/credit/account-users/{id}', [CreditAccountUserController::class, 'update']);
    Route::patch('/credit/account-users/{id}/toggle', [CreditAccountUserController::class, 'toggle']);

    Route::get('/credit/orders', [CreditOrderController::class, 'orders']);
    Route::get('/credit/orders/{id}', [CreditOrderController::class, 'showOrder']);
    Route::post('/credit/bills/{billId}/convert', [CreditOrderController::class, 'createFromBill']);
    Route::post('/credit/orders/{id}/approve', [CreditOrderController::class, 'approve']);
    Route::post('/credit/orders/{id}/reject', [CreditOrderController::class, 'reject']);
    Route::post('/credit/orders/{id}/settlements', [CreditOrderController::class, 'settle']);

    Route::get('/credit/reports/summary', [CreditOrderController::class, 'reportsSummary']);
});
