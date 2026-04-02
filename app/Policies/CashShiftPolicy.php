<?php

namespace App\Policies;

use App\Models\CashShift;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class CashShiftPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'shifts.manage'); }
    public function view(User $user, CashShift $model): bool { return $this->allows($user, 'shifts.manage'); }
    public function open(User $user): bool { return $this->allows($user, 'shifts.manage'); }
    public function close(User $user, CashShift $model): bool { return $this->allows($user, 'shifts.manage'); }
    public function current(User $user): bool { return $this->allows($user, 'shifts.manage'); }
}
