<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/public.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/customer.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/admin.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/waiter.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/cashier.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/manager.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/barman.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/kitchen.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/finance.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/auth.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/foodcontroller.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Add CORS middleware globally
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (ValidationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Forbidden.',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
            ], 404);
        });

        $exceptions->render(function (Throwable $e, $request) {
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Server error.',
            ], 500);
        });
    })
    ->create();