
<?php

use App\Http\Controllers\Api\PublicAuthController;
use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\PublicPaymentController;
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

    Route::get('/bills/{id}', [PublicPaymentController::class, 'showBill']);
    Route::post('/bills/{id}/payments', [PublicPaymentController::class, 'storePayment']);
});
