<?php

namespace App\Policies;

use App\Models\MenuCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class MenuCategoryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'menu.read'); }
    public function view(User $user, MenuCategory $model): bool { return $this->allows($user, 'menu.read'); }
    public function create(User $user): bool { return $this->allows($user, 'menu.create'); }
    public function update(User $user, MenuCategory $model): bool { return $this->allows($user, 'menu.update'); }
    public function toggleActive(User $user, MenuCategory $model): bool { return $this->allows($user, 'menu.disable'); }
    public function setAvailability(User $user): bool { return $this->allows($user, 'menu.disable'); }
}
