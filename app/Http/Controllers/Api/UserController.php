<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $perPage = max(1, (int) $request->get('per_page', 10));

        $query = User::query()->with('roles');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            }

            if ($request->status === 'disabled') {
                $query->where('is_active', false);
            }
        }

        $users = $query
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);
        $this->authorize('view', $user);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $user,
        ]);
    }

    public function rolesLite()
    {
        $this->authorize('rolesLite', User::class);
    
        $roles = Role::query()
            ->where('guard_name', 'sanctum')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    
        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
    
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:20|unique:users,phone',
            'password' => 'required|string|min:6',
            'role' => 'required|string|exists:roles,name',
        ]);
    
        $role = Role::where('name', $validated['role'])
            ->where('guard_name', 'sanctum')
            ->firstOrFail();
    
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);
    
        $user->syncRoles([$role->name]);
        $user->load('roles');
    
        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->authorize('update', $user);
    
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => "required|email|unique:users,email,{$id}",
            'role' => 'required|string|exists:roles,name',
        ]);
    
        $role = Role::where('name', $validated['role'])
            ->where('guard_name', 'sanctum')
            ->firstOrFail();
    
        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);
    
        $user->syncRoles([$role->name]);
        $user->load('roles');
    
        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    public function assignRole(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->authorize('assignRole', $user);
    
        $validated = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);
    
        $role = Role::where('name', $validated['role'])
            ->where('guard_name', 'sanctum')
            ->firstOrFail();
    
        $user->syncRoles([$role->name]);
        $user->load('roles');
    
        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $user,
        ]);
    }

    public function toggle($id)
    {
        $user = User::findOrFail($id);
        $this->authorize('toggle', $user);

        $user->is_active = !$user->is_active;
        $user->save();
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => $user,
        ]);
    }

    public function resetPassword(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $this->authorize('resetPassword', $user);

        $validated = $request->validate([
            'new_password' => 'required|string|min:6',
        ]);

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful',
            'data' => [
                'id' => $user->id,
            ],
        ]);
    }

    public function waitersLite(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
    
        $query = \App\Models\User::query()
            ->select('id', 'name')
            ->whereHas('roles', function ($q) {
                $q->where('name', 'Waiter');
            });
    
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
    
        $waiters = $query
            ->orderBy('name')
            ->get();
    
        return response()->json([
            'success' => true,
            'data' => $waiters,
        ]);
    }
   

    public function updateProfile(Request $request)
{
    $user = $request->user();

    $validated = $request->validate([
        'name' => 'required|string|max:100',
        'email' => 'required|email|unique:users,email,' . $user->id,
        'phone' => 'required|string|max:20|unique:users,phone,' . $user->id,
        'old_password' => 'nullable|string',
        'new_password' => 'nullable|string|min:6',
        'profile' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
    ]);

    $user->name = $validated['name'];
    $user->email = $validated['email'];
    $user->phone = $validated['phone'];

    if (!empty($validated['new_password'])) {
        if (empty($validated['old_password'])) {
            return response()->json([
                'success' => false,
                'message' => 'Old password is required to change password',
                'errors' => [
                    'old_password' => ['Old password is required when setting a new password.']
                ]
            ], 422);
        }

        if (!Hash::check($validated['old_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Old password is incorrect',
                'errors' => [
                    'old_password' => ['The provided old password is incorrect.']
                ]
            ], 422);
        }

        $user->password = Hash::make($validated['new_password']);
    }

    if ($request->hasFile('profile')) {
        if ($user->profile_image && \Storage::disk('public')->exists($user->profile_image)) {
            \Storage::disk('public')->delete($user->profile_image);
        }

        $path = $request->file('profile')->store('users/profile-images', 'public');
        $user->profile_image = $path;
    }

    $user->save();
    $user->load('roles');

    return response()->json([
        'success' => true,
        'message' => 'Profile updated successfully',
        'data' => $user,
    ]);
}

    
}