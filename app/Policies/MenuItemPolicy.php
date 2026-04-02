<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class MenuItemPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'menu.read'); }
    public function view(User $user, MenuItem $model): bool { return $this->allows($user, 'menu.read'); }
    public function create(User $user): bool { return $this->allows($user, 'menu.create'); }
    public function update(User $user, MenuItem $model): bool { return $this->allows($user, 'menu.update'); }
    public function uploadImage(User $user, MenuItem $model): bool { return $this->allows($user, 'menu.update'); }
    public function toggleActive(User $user, MenuItem $model): bool { return $this->allows($user, 'menu.disable'); }
    public function setAvailability(User $user, MenuItem $model): bool { return $this->allows($user, 'menu.disable'); }
}
