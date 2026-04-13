<?php

namespace App\Policies;

use App\Models\KitchenTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class KitchenTicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'kds.kitchen'); }
    public function update(User $user, KitchenTicket $model): bool { return $this->allows($user, 'kds.kitchen'); }
    public function accept(User $user, KitchenTicket $model): bool { return $this->allows($user, 'kds.kitchen'); }
    public function ready(User $user, KitchenTicket $model): bool { return $this->allows($user, 'kds.kitchen'); }
    public function served(User $user, KitchenTicket $model): bool { return $this->allows($user, 'kds.kitchen'); }
    public function reject(User $user, KitchenTicket $model): bool { return $this->allows($user, 'kds.kitchen'); }
}
