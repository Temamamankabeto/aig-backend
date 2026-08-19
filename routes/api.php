<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;

Route::middleware([SecurityHeaders::class, EnsureUserIsActive::class])->group(function () {
    Route::get('/ping', fn () => response()->json([
        'success' => true,
        'message' => 'API is working',
        'data' => ['time' => now()],
        'meta' => null,
    ]));

    require __DIR__ . '/auth.php';
    require __DIR__ . '/public.php';
    require __DIR__ . '/customer.php';
    require __DIR__ . '/admin.php';
    require __DIR__ . '/waiter.php';
    require __DIR__ . '/cashier.php';
    require __DIR__ . '/manager.php';
    require __DIR__ . '/foodcontroller.php';
    require __DIR__ . '/purchaser.php';
    require __DIR__ . '/stockkeeper.php';
    require __DIR__ . '/barman.php';
    require __DIR__ . '/kitchen.php';
    require __DIR__ . '/finance.php';
    require __DIR__ . '/reports.php';
    require __DIR__ . '/credit.php';
    require __DIR__ . '/packages.php';
    require __DIR__ . '/notifications.php';
    require __DIR__ . '/inventory-custody.php';
});
