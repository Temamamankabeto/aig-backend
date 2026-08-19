<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user && ! $user->is_active) {
            $user->tokens()->delete();
            $user->forceFill([
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
            ])->save();

            return response()->json([
                'success' => false,
                'message' => 'Your account is disabled. Please contact the administrator.',
                'data' => null,
                'meta' => null,
            ], 403);
        }

        return $next($request);
    }
}
