<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class UserPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'users.read'); }
    public function view(User $user, User $model): bool { return $this->allows($user, 'users.read'); }
    public function create(User $user): bool { return $this->allows($user, 'users.create'); }
    public function update(User $user, User $model): bool { return $this->allows($user, 'users.update'); }
    public function toggle(User $user, User $model): bool { return $this->allows($user, 'users.disable'); }
    public function resetPassword(User $user, User $model): bool { return $this->allows($user, 'users.disable'); }
    public function assignRole(User $user, User $model): bool { return $this->allows($user, 'roles.assign'); }
    public function rolesLite(User $user): bool { return $this->allows($user, 'users.read'); }
    public function waitersLite(User $user): bool { return $this->allows($user, 'users.read'); }
}
