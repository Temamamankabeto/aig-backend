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
    public function delete(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.destroy'); }
    public function destroy(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.destroy'); }
    public function restore(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.update', 'inventory.destroy'); }
    public function forceDelete(User $user, InventoryItem $model): bool { return $this->allows($user, 'inventory.destroy'); }
}
