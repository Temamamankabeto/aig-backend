<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class InventoryItemPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'inventory.read'); }
    public function view(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.read'); }
    public function create(User $user): bool { return $this->allows($user, 'inventory.create'); }
    public function update(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.update'); }

    public function create(User $user): bool { return $this->allows($user, 'inventory.create'); }
    public function update(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.update'); }
}
