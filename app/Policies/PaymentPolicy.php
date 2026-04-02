<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class PaymentPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool { return $this->allows($user, 'payments.read'); }
    public function view(User $user, Payment $payment): bool { return $this->allows($user, 'payments.read'); }
    public function create(User $user): bool { return $this->allows($user, 'payments.create'); }
    public function submitByWaiter(User $user): bool { return $this->allows($user, 'payments.create', 'orders.read'); }
    public function pendingApproval(User $user): bool { return $this->allows($user, 'payments.read', 'orders.read'); }
    public function approve(User $user, Payment $payment): bool { return $this->allows($user, 'payments.read', 'payments.approve', 'orders.read'); }
    public function returnPayment(User $user, Payment $payment): bool { return $this->allows($user, 'payments.read', 'payments.approve', 'orders.read'); }
    public function fail(User $user, Payment $payment): bool { return $this->allows($user, 'payments.read', 'payments.approve', 'orders.read'); }
    public function waiterReport(User $user): bool { return $this->allows($user, 'payments.read', 'orders.read'); }
}
