<?php

namespace App\Policies;

use App\Models\RefundRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class RefundRequestPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'refunds.read'); }
    public function view(User $user, RefundRequest $model): bool { return $this->allows($user, 'refunds.read'); }
    public function create(User $user): bool { return $this->allows($user, 'refunds.request'); }
    public function approve(User $user, RefundRequest $model): bool { return $this->allows($user, 'refunds.approve'); }
    public function reject(User $user, RefundRequest $model): bool { return $this->allows($user, 'refunds.approve'); }
    public function processRefund(User $user, RefundRequest $model): bool { return $this->allows($user, 'refunds.approve'); }
}
