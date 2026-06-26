<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class RefreshTokenController extends Controller
{
    public function refresh(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token') ?? $request->input('refresh_token');

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token missing',
            ], 401);
        }

        $hashed = hash('sha256', $refreshToken);

        $user = User::where('refresh_token', $hashed)
            ->whereNotNull('refresh_token_expires_at')
            ->where('refresh_token_expires_at', '>', now())
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
            ], 401);
        }

        $newAccessToken = $user->createToken('aig-api-token')->plainTextToken;

        $newRefreshToken = Str::random(64);

        $user->update([
            'refresh_token' => hash('sha256', $newRefreshToken),
            'refresh_token_expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'success' => true,
            'token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
        ])->cookie(
            'refresh_token',
            $newRefreshToken,
            60 * 24 * 30,
            '/',
            null,
            false,
            true
        );
    }
}
