<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class OrderPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'orders.read') || $this->hasOperationalOrderRole($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, 'orders.read') || $this->hasOperationalOrderRole($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'orders.create') || $this->hasAnyRoleName($user, [
            'cashier',
            'waiter',
            'cafeteria manager',
            'manager',
            'general admin',
            'admin',
        ]);
    }

    public function update(User $user, Order $order): bool
    {
        if ($this->allows($user, 'orders.update')) {
            return true;
        }

        if ($this->isBillPrinted($order)) {
            return false;
        }

        if ($this->hasAnyRoleName($user, ['cashier', 'cafeteria manager', 'manager', 'general admin', 'admin'])) {
            return true;
        }

        if ($this->hasAnyRoleName($user, ['waiter'])) {
            return (int) $order->created_by === (int) $user->id
                || (int) $order->waiter_id === (int) $user->id;
        }

        return false;
    }

    public function requestCancel(User $user, ?Order $order = null): bool
    {
        return $this->allows($user, 'orders.request.cancel', 'orders.update')
            || $this->hasOperationalOrderRole($user);
    }

    public function waiterReports(User $user): bool
    {
        return $this->allows($user, 'orders.read') || $this->hasAnyRoleName($user, ['waiter', 'manager', 'cafeteria manager', 'general admin', 'admin']);
    }

    private function isBillPrinted(Order $order): bool
    {
        $order->loadMissing('bill');

        return (bool) ($order->bill_printed_at || $order->bill?->issued_at);
    }

    private function hasOperationalOrderRole(User $user): bool
    {
        return $this->hasAnyRoleName($user, [
            'cashier',
            'waiter',
            'cafeteria manager',
            'manager',
            'general admin',
            'admin',
        ]);
    }

    private function hasAnyRoleName(User $user, array $roles): bool
    {
        $normalized = collect($roles)->map(fn ($role) => strtolower(trim($role)))->all();

        return $user->roles
            ->pluck('name')
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->intersect($normalized)
            ->isNotEmpty();
    }
}
