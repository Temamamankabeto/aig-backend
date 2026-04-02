<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PurchaseOrderPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'purchases.read'); }
    public function view(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.read'); }
    public function create(User $user): bool { return $this->allows($user, 'purchases.create'); }
    public function approve(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.approve'); }
    public function cancel(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.approve'); }
}
