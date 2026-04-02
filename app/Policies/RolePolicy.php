<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
   public function viewAny(User $user): bool
   {
   return true;
   }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.read');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.create') || $user->can('roles.assign');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.update') || $user->can('roles.assign');
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        return $user->can('roles.assign');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.delete');
    }
}