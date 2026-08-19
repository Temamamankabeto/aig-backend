<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class PublicAuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => 'required|string|max:20',
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole(RoleNames::CUSTOMER);

        $token = $user->createToken('public_auth', ['*'], now()->addMinutes(config('auth_security.access_token_ttl_minutes', 15)))->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => 'nullable|string|max:255',
            'identifier' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
            'password' => 'required|string',
        ]);

        $login = trim((string) ($validated['login'] ?? $validated['identifier'] ?? $validated['email'] ?? ''));

        if ($login === '') {
            return response()->json([
                'success' => false,
                'message' => 'Email or phone number is required.',
            ], 422);
        }

        $rateLimitKey = 'public-login:'.hash('sha256', Str::lower($login).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, config('auth_security.max_login_attempts', 5))) {
            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Try again later.',
                'data' => null,
                'meta' => ['retry_after_seconds' => RateLimiter::availableIn($rateLimitKey)],
            ], 429);
        }

        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($rateLimitKey, config('auth_security.lockout_seconds', 900));
            return response()->json([
                'success' => false,
                'message' => 'Invalid email/phone number or password.',
            ], 422);
        }

        if (! $user->is_active) {
            RateLimiter::hit($rateLimitKey, config('auth_security.lockout_seconds', 900));
            return response()->json([
                'success' => false,
                'message' => 'Your account is disabled.',
                'data' => null,
                'meta' => null,
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);
        $user->tokens()->delete();
        $token = $user->createToken('public_auth', ['*'], now()->addMinutes(config('auth_security.access_token_ttl_minutes', 15)))->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
        ]);
    }
}
