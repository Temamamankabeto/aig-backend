<?php

namespace App\Policies;

use App\Models\DiningTable;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class DiningTablePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'tables.read'); }
    public function view(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.read'); }
    public function create(User $user): bool { return $this->allows($user, 'tables.create'); }
    public function update(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.update'); }
    public function assignWaiter(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.assign'); }
    public function transfer(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.transfer'); }
    public function setStatus(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.update'); }
    public function toggleActive(User $user, DiningTable $model): bool { return $this->allows($user, 'tables.update'); }
}
