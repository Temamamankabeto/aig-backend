<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('permissions.read') || $user->can('permission.read');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->can('permissions.read') || $user->can('permission.read');
    }

    public function create(User $user): bool
    {
        return $user->can('permissions.create') || $user->can('permission.create');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->can('permissions.update') || $user->can('permission.update');
    }

    public function delete(User $user, Permission $permission): bool
    {
        return $user->can('permissions.delete') || $user->can('permission.delete');
    }
}