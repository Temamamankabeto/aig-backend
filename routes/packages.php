<?php

use App\Http\Controllers\Api\PackageOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/packages', [PackageOrderController::class, 'packages']);
    Route::post('/packages', [PackageOrderController::class, 'storePackage']);
    Route::get('/packages/{id}', [PackageOrderController::class, 'showPackage']);
    Route::put('/packages/{id}', [PackageOrderController::class, 'updatePackage']);
    Route::delete('/packages/{id}', [PackageOrderController::class, 'deletePackage']);

    Route::get('/package-orders', [PackageOrderController::class, 'orders']);
    Route::post('/package-orders', [PackageOrderController::class, 'storeOrder']);
    Route::get('/package-orders/report/summary', [PackageOrderController::class, 'reportSummary']);
    Route::get('/package-orders/{id}', [PackageOrderController::class, 'showOrder']);
    Route::put('/package-orders/{id}', [PackageOrderController::class, 'updateOrder']);
    Route::post('/package-orders/{id}/quote', [PackageOrderController::class, 'quote']);
    Route::post('/package-orders/{id}/approve', [PackageOrderController::class, 'approve']);
    Route::post('/package-orders/{id}/schedule', [PackageOrderController::class, 'schedule']);
    Route::post('/package-orders/{id}/start-preparation', [PackageOrderController::class, 'startPreparation']);
    Route::post('/package-orders/{id}/mark-ready', [PackageOrderController::class, 'markReady']);
    Route::post('/package-orders/{id}/deliver', [PackageOrderController::class, 'deliver']);
    Route::post('/package-orders/{id}/service', [PackageOrderController::class, 'updateService']);
    Route::post('/package-orders/{id}/complete', [PackageOrderController::class, 'complete']);
    Route::post('/package-orders/{id}/cancel', [PackageOrderController::class, 'cancel']);
    Route::post('/package-orders/{id}/payments', [PackageOrderController::class, 'payment']);
});
