<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class OrderPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'orders.read'); }
    public function view(User $user, Order $order): bool { return $this->allows($user, 'orders.read'); }
    public function create(User $user): bool { return $this->allows($user, 'orders.create'); }
    public function update(User $user, Order $order): bool { return $this->allows($user, 'orders.update'); }
    public function requestCancel(User $user, ?Order $order = null): bool { return $this->allows($user, 'orders.request.cancel', 'orders.update'); }
    public function waiterReports(User $user): bool { return $this->allows($user, 'orders.read'); }
}
