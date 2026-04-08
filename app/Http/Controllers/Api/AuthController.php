<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; 
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
class AuthController extends Controller
{
    /**
     * Register a new customer
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'confirmed', 'min:8'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'password' => Hash::make($request->password),
        ]);

        $user->assignRole('customer');

        $token = $user->createToken('aig-api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ], 201);
    }

    /**
     * Login user
     */
   public function login(Request $request)
{
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['Invalid email or password.'],
        ]);
    }

    // ---------------- Access Token ----------------
    $accessToken = $user->createToken('aig-api-token')->plainTextToken;

    // ---------------- Refresh Token ----------------
    $refreshToken = Str::random(64); // generate a secure random token

    // Store refresh token in DB (or cache) with user_id
    $user->update([
        'refresh_token' => hash('sha256', $refreshToken),
        'refresh_token_expires_at' => now()->addDays(30), // optional expiration
    ]);

    // ---------------- Cookie for Web ----------------
   $cookie = cookie(
    'refresh_token',
    $refreshToken,
    60 * 24 * 30,
    '/',
    null,
    false, // http, dev
    true
)->withSameSite('Lax'); // Lax works locally
    // ---------------- JSON Response ----------------
    return response()->json([
        'success' => true,
        'token' => $accessToken,
        'refresh_token' => $refreshToken, // optional in JSON, mobile apps need it
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
        ],
        'roles' => $user->getRoleNames()->values()->all(),
        'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
    ])->cookie($cookie);
}

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}