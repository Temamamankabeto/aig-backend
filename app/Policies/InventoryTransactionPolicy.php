<?php

namespace App\Policies;

use App\Models\InventoryTransaction;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class InventoryTransactionPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'inventory.read'); }
    public function adjust(User $user): bool { return $this->allows($user, 'inventory.adjust'); }
    public function waste(User $user): bool { return $this->allows($user, 'inventory.adjust'); }
}
