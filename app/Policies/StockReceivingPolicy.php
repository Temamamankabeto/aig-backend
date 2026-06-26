<?php

namespace App\Policies;

use App\Models\StockReceiving;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class StockReceivingPolicy
{
    use ChecksPermissions;

    public function receive(User $user): bool { return $this->allows($user, 'stock.receive', 'stock_receiving.approve'); }
    public function viewAny(User $user): bool { return $this->allows($user, 'stock.receive', 'stock_receiving.approve', 'inventory.read'); }
    public function view(User $user, StockReceiving $model): bool { return $this->allows($user, 'stock.receive', 'stock_receiving.approve', 'inventory.read'); }
}
