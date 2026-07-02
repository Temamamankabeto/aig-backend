<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PublicAuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'role' => 'customer',
        ]);

        if (method_exists($user, 'assignRole')) {
            try {
                $user->assignRole('customer');
            } catch (\Throwable $e) {
            }
        }

        $token = $user->createToken('public_auth')->plainTextToken;

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

        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email/phone number or password.',
            ], 422);
        }

        $token = $user->createToken('public_auth')->plainTextToken;

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