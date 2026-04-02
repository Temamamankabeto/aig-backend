<?php

namespace App\Policies;

use App\Models\Bill;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BillPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'bills.read'); }
    public function view(User $user, Bill $bill): bool { return $this->allows($user, 'bills.read'); }
    public function createOrUpdateDraft(User $user): bool { return $this->allows($user, 'bills.issue'); }
    public function issue(User $user, Bill $bill): bool { return $this->allows($user, 'bills.issue'); }
    public function void(User $user, Bill $bill): bool { return $this->allows($user, 'bills.issue'); }
}
