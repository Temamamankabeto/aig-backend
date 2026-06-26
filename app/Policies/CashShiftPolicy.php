<?php

namespace App\Policies;

use App\Models\CashShift;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class CashShiftPolicy
{
    use ChecksPermissions;

    private function canRead(User $user): bool
    {
        return $this->allows($user, 'cash_shift.read') || $this->allows($user, 'shifts.manage');
    }

    private function canOpen(User $user): bool
    {
        return $this->allows($user, 'cash_shift.open') || $this->allows($user, 'shifts.manage');
    }

    private function canClose(User $user): bool
    {
        return $this->allows($user, 'cash_shift.close') || $this->allows($user, 'shifts.manage');
    }

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, CashShift $model): bool
    {
        return $this->canRead($user) && ((int) $model->cashier_id === (int) $user->id || $this->allows($user, 'reports.financial.read'));
    }

    public function open(User $user): bool
    {
        return $this->canOpen($user);
    }

    public function close(User $user, CashShift $model): bool
    {
        return $this->canClose($user) && (int) $model->cashier_id === (int) $user->id;
    }

    public function update(User $user, CashShift $model): bool
    {
        return $this->canClose($user) && (int) $model->cashier_id === (int) $user->id;
    }

    public function current(User $user): bool
    {
        return $this->canRead($user) || $this->canOpen($user) || $this->canClose($user);
    }
}
