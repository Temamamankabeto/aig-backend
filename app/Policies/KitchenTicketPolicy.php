<?php

namespace App\Policies;

use App\Models\KitchenTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class KitchenTicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    public function update(User $user, KitchenTicket $model): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    public function accept(User $user, KitchenTicket $model): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    public function ready(User $user, KitchenTicket $model): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    public function served(User $user, KitchenTicket $model): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    public function reject(User $user, KitchenTicket $model): bool
    {
        return $this->canManageKitchenTickets($user);
    }

    private function canManageKitchenTickets(User $user): bool
    {
        return $this->allows($user, 'kds.kitchen')
            || $this->hasAnyRoleName($user, [
                'kitchen',
                'kitchen staff',
                'kitchen_staff',
                'chef',
            ]);
    }

    private function hasAnyRoleName(User $user, array $roles): bool
    {
        $allowed = collect($roles)
            ->map(fn (string $role) => $this->normalizeRoleName($role))
            ->all();

        return $user->roles()
            ->pluck('name')
            ->map(fn ($role) => $this->normalizeRoleName((string) $role))
            ->intersect($allowed)
            ->isNotEmpty();
    }

    private function normalizeRoleName(string $role): string
    {
        return str_replace(['-', '_'], ' ', strtolower(trim($role)));
    }
}
