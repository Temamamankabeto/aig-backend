<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'password' => Hash::make($request->password),
            'is_active' => true,
        ]);

        $user->assignRole(RoleNames::CUSTOMER);

        $accessToken = $this->issueAccessToken($user);
        $refreshToken = Str::random(64);

        $user->forceFill([
            'refresh_token' => hash('sha256', $refreshToken),
            'refresh_token_expires_at' => now()->addMinutes(config('auth_security.refresh_token_ttl_minutes', 43200)),
        ])->save();

        return response()->json($this->authPayload($user, $accessToken), 201)
            ->cookie($this->refreshCookie($refreshToken));
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'login' => ['nullable', 'string', 'max:255'],
            'identifier' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($validated['login'] ?? $validated['identifier'] ?? $validated['email'] ?? ''));

        if ($login === '') {
            throw ValidationException::withMessages([
                'login' => ['Email or phone number is required.'],
            ]);
        }

        $rateLimitKey = $this->loginRateLimitKey($request, $login);
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
            throw ValidationException::withMessages([
                'login' => ['Invalid email/phone number or password.'],
            ]);
        }

        if (!$user->is_active) {
            RateLimiter::hit($rateLimitKey, config('auth_security.lockout_seconds', 900));
            return response()->json([
                'success' => false,
                'message' => 'Your account is disabled. Please contact the administrator.',
            ], 403);
        }

        RateLimiter::clear($rateLimitKey);
        $user->tokens()->delete();
        $accessToken = $this->issueAccessToken($user);
        $refreshToken = Str::random(64);

        $user->forceFill([
            'refresh_token' => hash('sha256', $refreshToken),
            'refresh_token_expires_at' => now()->addMinutes(config('auth_security.refresh_token_ttl_minutes', 43200)),
        ])->save();

        return response()->json($this->authPayload($user, $accessToken))
            ->cookie($this->refreshCookie($refreshToken));
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->userPayload($user),
            'user' => $this->userPayload($user),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        $user?->tokens()->delete();
        $user?->forceFill([
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ])->withoutCookie('refresh_token', '/', config('auth_security.cookie_domain'));
    }

    protected function authPayload(User $user, string $accessToken): array
    {
        return [
            'success' => true,
            'message' => 'Authenticated successfully',
            'token' => $accessToken,
            'user' => $this->userPayload($user),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'department_id' => $user->department_id,
            'status' => $user->is_active ? 'active' : 'disabled',
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    protected function refreshCookie(string $refreshToken)
    {
        return cookie(
            'refresh_token',
            $refreshToken,
            config('auth_security.refresh_token_ttl_minutes', 43200),
            '/',
            config('auth_security.cookie_domain'),
            app()->environment('production'),
            true
        )->withSameSite(config('auth_security.cookie_same_site', 'lax'));
    }

    protected function issueAccessToken(User $user): string
    {
        return $user->createToken(
            'aig-api-token',
            ['*'],
            now()->addMinutes(config('auth_security.access_token_ttl_minutes', 15))
        )->plainTextToken;
    }

    protected function loginRateLimitKey(Request $request, string $login): string
    {
        return 'login:'.hash('sha256', Str::lower($login).'|'.$request->ip());
    }
}
