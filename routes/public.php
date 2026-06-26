
<?php

use App\Http\Controllers\Api\PublicAuthController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\PublicPaymentController;
use App\Http\Controllers\Api\PublicCreditCardOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->group(function () {
    Route::get('/menu', [PublicMenuController::class, 'index']);
    Route::get('/menu/categories', [PublicMenuController::class, 'categories']);
    Route::get('/menu/{id}', [PublicMenuController::class, 'show']);

    Route::get('/tables', [PublicOrderController::class, 'tables']);

    Route::post('/register', [PublicAuthController::class, 'register']);
    Route::post('/login', [PublicAuthController::class, 'login']);

    Route::post('/orders', [PublicOrderController::class, 'store']);
    Route::get('/orders/{orderNumber}', [PublicOrderController::class, 'show']);

    Route::post('/credit-card/validate', [PublicCreditCardOrderController::class, 'validateCard']);
    Route::get('/credit-card/menu', [PublicCreditCardOrderController::class, 'menu']);
    Route::post('/credit-card/orders', [PublicCreditCardOrderController::class, 'store']);

    Route::get('/bills/{id}', [PublicPaymentController::class, 'showBill']);
    Route::post('/bills/{id}/payments', [PublicPaymentController::class, 'storePayment']);
});
