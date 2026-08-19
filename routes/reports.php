<?php

use App\Http\Controllers\Api\SalesReportController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    'role:F&B Controller|Manager|Finance|Cashier|General Admin',
    'permission:reports.sales.read',
])->prefix('reports')->group(function (): void {
    Route::get('/cashiers', [SalesReportController::class, 'cashiers']);
    Route::get('/sold-items', [SalesReportController::class, 'soldItems']);
});
