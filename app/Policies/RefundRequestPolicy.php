<?php

namespace App\Policies;

use App\Models\RefundRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RefundRequestPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'payments.read', 'payments.refund.request', 'payments.refund.approve'); }
    public function view(User $user, RefundRequest $model): bool
    {
        return $this->allows($user, 'payments.refund.approve')
            || ($this->allows($user, 'payments.refund.request') && (int) $model->requested_by === (int) $user->id);
    }
    public function create(User $user): bool { return $this->allows($user, 'payments.refund.request'); }
    public function approve(User $user, RefundRequest $model): bool { return $this->allows($user, 'payments.refund.approve'); }
    public function reject(User $user, RefundRequest $model): bool { return $this->approve($user, $model); }
    public function processRefund(User $user, RefundRequest $model): bool { return $this->approve($user, $model); }
}
