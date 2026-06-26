<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    protected string $guard = 'sanctum';

    public function paginateRoles(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        $query = Role::query()
            ->where('guard_name', $this->guard)
            ->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->paginate(
            $perPage,
            ['id', 'name', 'guard_name', 'created_at', 'updated_at']
        );
    }

    public function transformPaginatedRoles(LengthAwarePaginator $roles): array
    {
        return [
            'success' => true,
            'data' => $roles->items(),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'last_page' => $roles->lastPage(),
            ],
        ];
    }

    public function getRole(int|string $id): Role
    {
        return Role::query()
            ->where('guard_name', $this->guard)
            ->findOrFail($id);
    }

    public function getPermissions(?string $search = null)
    {
        $query = Permission::query()
            ->where('guard_name', $this->guard)
            ->orderBy('name');

        $search = trim((string) $search);

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get(['id', 'name', 'guard_name']);
    }

    public function createRole(array $data): Role
    {
        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $this->guard,
        ]);

        $this->clearPermissionCache();

        return $role;
    }

    public function updateRole(Role $role, array $data): Role
    {
        $role->update([
            'name' => $data['name'],
        ]);

        $this->clearPermissionCache();

        return $role->fresh();
    }

    public function getRolePermissions(Role $role)
    {
        return $role->permissions()
            ->where('guard_name', $this->guard)
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);
    }

    public function assignPermissions(Role $role, array $permissionNames = []): array
    {
        $permNames = collect($permissionNames)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values()
            ->all();

        $existingPermissions = Permission::query()
            ->where('guard_name', $this->guard)
            ->whereIn('name', $permNames)
            ->get();

        $role->syncPermissions($existingPermissions);

        $this->clearPermissionCache();

        return [
            'role_id' => $role->id,
            'assigned_count' => $existingPermissions->count(),
            'permissions' => $existingPermissions->pluck('name')->values(),
        ];
    }

    protected function clearPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}