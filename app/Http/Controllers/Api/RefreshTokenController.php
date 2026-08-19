<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefreshTokenController extends Controller
{
    public function refresh(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token');

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token missing',
            ], 401);
        }

        $hashed = hash('sha256', $refreshToken);

        [$user, $newAccessToken, $newRefreshToken] = DB::transaction(function () use ($hashed) {
            $user = User::query()
                ->where('refresh_token', $hashed)
                ->whereNotNull('refresh_token_expires_at')
                ->where('refresh_token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $user || ! $user->is_active) {
                return [null, null, null];
            }

            $user->tokens()->delete();
            $newRefreshToken = Str::random(64);
            $user->forceFill([
                'refresh_token' => hash('sha256', $newRefreshToken),
                'refresh_token_expires_at' => now()->addMinutes(config('auth_security.refresh_token_ttl_minutes', 43200)),
            ])->save();

            $newAccessToken = $user->createToken(
                'aig-api-token',
                ['*'],
                now()->addMinutes(config('auth_security.access_token_ttl_minutes', 15))
            )->plainTextToken;

            return [$user, $newAccessToken, $newRefreshToken];
        });

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token',
                'data' => null,
                'meta' => null,
            ], 401)->withoutCookie('refresh_token', '/', config('auth_security.cookie_domain'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Access token refreshed.',
            'data' => ['token' => $newAccessToken],
            'token' => $newAccessToken,
            'meta' => null,
        ])->cookie(cookie(
            'refresh_token',
            $newRefreshToken,
            config('auth_security.refresh_token_ttl_minutes', 43200),
            '/',
            config('auth_security.cookie_domain'),
            app()->environment('production'),
            true
        )->withSameSite(config('auth_security.cookie_same_site', 'lax')));
    }
}
