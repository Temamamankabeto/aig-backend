<?php

namespace App\Policies;

use App\Models\StockReceiving;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class StockReceivingPolicy
{
    use ChecksPermissions;

    public function receive(User $user): bool { return $this->allows($user, 'stock.receive'); }
}
