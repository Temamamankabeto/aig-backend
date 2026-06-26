<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PurchaseOrderPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'purchases.read', 'purchase_orders.read'); }
    public function view(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.read', 'purchase_orders.read'); }
    public function create(User $user): bool { return $this->allows($user, 'purchases.create', 'purchase_orders.create'); }
    public function approve(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.approve', 'purchase_orders.approve'); }
    public function cancel(User $user, PurchaseOrder $model): bool { return $this->allows($user, 'purchases.approve', 'purchase_orders.approve'); }
    public function validate(User $user, PurchaseOrder $model): bool
    {
        return $this->allows($user, 'purchases.validate', 'purchase_orders.validate', 'recipes.integrity.read');
    }
}
