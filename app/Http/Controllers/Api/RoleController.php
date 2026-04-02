<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    protected string $guard = 'sanctum';

    public function index(Request $request)
    {
        $this->authorize('viewAny', Role::class);
        $search = trim((string) $request->query('search', ''));

        $query = Role::query()
            ->where('guard_name', $this->guard)
            ->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $roles = $query->get(['id', 'name', 'guard_name', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    public function permissions(Request $request)
    {
        $this->authorize('viewAny', Permission::class);
        $query = Permission::query()
            ->where('guard_name', $this->guard)
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        $permissions = $query->get(['id', 'name', 'guard_name']);

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Role::class);
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('guard_name', $this->guard)),
            ],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $this->guard,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Role created',
            'data' => $role,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $role = Role::where('guard_name', $this->guard)->findOrFail($id);
        $this->authorize('update', $role);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')
                    ->ignore($role->id)
                    ->where(fn ($q) => $q->where('guard_name', $this->guard)),
            ],
        ]);

        $role->update([
            'name' => $data['name'],
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Role updated',
            'data' => $role,
        ]);
    }

    public function rolePermissions($id)
    {
        $role = Role::where('guard_name', $this->guard)->findOrFail($id);
        $this->authorize('view', $role);

        $permissions = $role->permissions()
            ->where('guard_name', $this->guard)
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    public function assignPermissions(Request $request, $id)
    {
        $role = Role::where('guard_name', $this->guard)->findOrFail($id);
        $this->authorize('update', $role);

        $data = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $permNames = collect($data['permissions'] ?? [])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        $existingPermissions = Permission::query()
            ->where('guard_name', $this->guard)
            ->whereIn('name', $permNames)
            ->get();

        $role->syncPermissions($existingPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated',
            'data' => [
                'role_id' => $role->id,
                'assigned_count' => $existingPermissions->count(),
                'permissions' => $existingPermissions->pluck('name')->values(),
            ],
        ]);
    }
}