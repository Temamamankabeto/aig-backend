<?php

namespace App\Policies;

use App\Models\BarTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BarTicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'kds.bar'); }
    public function update(User $user, BarTicket $model): bool { return $this->allows($user, 'kds.bar'); }
    public function accept(User $user, BarTicket $model): bool { return $this->allows($user, 'kds.bar'); }
    public function ready(User $user, BarTicket $model): bool { return $this->allows($user, 'kds.bar'); }
    public function served(User $user, BarTicket $model): bool { return $this->allows($user, 'kds.bar'); }
    public function reject(User $user, BarTicket $model): bool { return $this->allows($user, 'kds.bar'); }
}
