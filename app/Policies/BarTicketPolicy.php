<?php

namespace App\Policies;

use App\Models\BarTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

class BarTicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->canManageBarTickets($user);
    }

    public function update(User $user, BarTicket $model): bool
    {
        return $this->canManageBarTickets($user);
    }

    public function accept(User $user, BarTicket $model): bool
    {
        return $this->canManageBarTickets($user);
    }

    public function ready(User $user, BarTicket $model): bool
    {
        return $this->canManageBarTickets($user);
    }

    public function served(User $user, BarTicket $model): bool
    {
        return $this->canManageBarTickets($user);
    }

    public function reject(User $user, BarTicket $model): bool
    {
        return $this->canManageBarTickets($user);
    }

    private function canManageBarTickets(User $user): bool
    {
        return $this->allows($user, 'kds.bar')
            || $this->hasAnyRoleName($user, [
                'bar',
                'bar staff',
                'bar_staff',
                'barman',
                'bartender',
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
