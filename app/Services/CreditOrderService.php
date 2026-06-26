<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\CreditAccount;
use App\Models\CreditAccountUser;
use App\Models\CreditApprovalLog;
use App\Models\CreditOrder;
use App\Models\CreditOrderAuthorizedUser;
use App\Models\CreditSettlement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreditOrderService
{
    public function createForBill(
        Bill $bill,
        int $creditAccountId,
        int $userId,
        ?string $dueDate = null,
        ?string $notes = null,
        bool $overrideLimit = false,
        int|array|null $creditAccountUserIds = null,
        bool $allowDailyLimitOverage = false
    ): CreditOrder {
        return DB::transaction(function () use ($bill, $creditAccountId, $userId, $dueDate, $notes, $overrideLimit, $creditAccountUserIds, $allowDailyLimitOverage) {
            $bill = Bill::with('order')->lockForUpdate()->findOrFail($bill->id);
            $account = CreditAccount::lockForUpdate()->findOrFail($creditAccountId);

            if (!$account->is_credit_enabled || $account->status !== 'active') {
                throw new RuntimeException('Credit account is not active or credit is disabled.');
            }

            $total = round((float) $bill->total, 2);
            $available = round((float) $account->credit_limit - (float) $account->current_balance, 2);

            if (!$overrideLimit && $total > $available) {
                throw new RuntimeException('Credit limit exceeded. Remaining limit: ' . number_format($available, 2));
            }

            $authorizedUsers = collect();
            if (strtolower((string) $account->account_type) === 'organization') {
                $ids = collect(is_array($creditAccountUserIds) ? $creditAccountUserIds : [$creditAccountUserIds])
                    ->filter(fn ($id) => !empty($id))
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                if ($ids->isEmpty()) {
                    throw new RuntimeException('At least one authorized person is required for organization credit account.');
                }

                $authorizedUsers = CreditAccountUser::where('credit_account_id', $account->id)
                    ->whereIn('id', $ids->all())
                    ->lockForUpdate()
                    ->get();

                if ($authorizedUsers->count() !== $ids->count()) {
                    throw new RuntimeException('One or more selected authorized persons are invalid for this credit account.');
                }

                if ($authorizedUsers->contains(fn ($user) => ! (bool) $user->is_active)) {
                    throw new RuntimeException('One or more selected authorized persons are disabled for this credit account.');
                }
            }

            $allocationAmount = $authorizedUsers->count() > 0
                ? round($total / max(1, $authorizedUsers->count()), 2)
                : 0;
            $allocations = [];
            $remainingAllocation = $total;

            foreach ($authorizedUsers->values() as $index => $authorizedUser) {
                $amount = $index === $authorizedUsers->count() - 1
                    ? round($remainingAllocation, 2)
                    : $allocationAmount;
                $remainingAllocation = round($remainingAllocation - $amount, 2);

                $this->assertAuthorizedUserLimit($authorizedUser, $amount, $overrideLimit, $allowDailyLimitOverage);
                $allocations[] = [$authorizedUser, $amount];
            }

            $primaryAuthorizedUser = $authorizedUsers->first();
            $usedByName = $authorizedUsers->isNotEmpty()
                ? $authorizedUsers->map(fn ($user) => trim($user->full_name . ($user->position ? ' (' . $user->position . ')' : '')))->implode(', ')
                : null;
            $usedByPhone = $authorizedUsers->isNotEmpty()
                ? $authorizedUsers->map(fn ($user) => trim((string) $user->phone))->filter()->implode(', ')
                : null;

            $status = 'credit_approved';
            $creditOrder = CreditOrder::create([
                'order_id' => $bill->order_id,
                'bill_id' => $bill->id,
                'credit_account_id' => $account->id,
                'credit_account_user_id' => $primaryAuthorizedUser?->id,
                'used_by_name' => $usedByName,
                'used_by_phone' => $usedByPhone,
                'credit_reference' => $this->reference(),
                'total_amount' => $total,
                'paid_amount' => 0,
                'remaining_amount' => $total,
                'status' => $status,
                'approved_by' => $userId,
                'approved_at' => now(),
                'due_date' => $this->calculateDueDateFromAccount($account),
                'notes' => $notes,
            ]);

            foreach ($allocations as [$authorizedUser, $amount]) {
                CreditOrderAuthorizedUser::create([
                    'credit_order_id' => $creditOrder->id,
                    'credit_account_id' => $account->id,
                    'credit_account_user_id' => $authorizedUser->id,
                    'allocated_amount' => $amount,
                    'full_name' => $authorizedUser->full_name,
                    'phone' => $authorizedUser->phone,
                    'position' => $authorizedUser->position,
                    'employee_id' => $authorizedUser->employee_id,
                ]);
            }

            $account->current_balance = round((float) $account->current_balance + $total, 2);
            $account->save();

            $bill->update([
                'bill_type' => 'credit',
                'credit_status' => $status,
                'status' => 'issued',
                'due_date' => $creditOrder->due_date,
                'issued_by' => $bill->issued_by ?: $userId,
                'issued_at' => $bill->issued_at ?: now(),
            ]);

            $bill->order?->update([
                'payment_type' => 'credit',
                'credit_status' => $status,
                'credit_account_id' => $account->id,
            ]);

            CreditApprovalLog::create([
                'credit_order_id' => $creditOrder->id,
                'action' => 'created',
                'actor_id' => $userId,
                'note' => $notes,
            ]);

            return $creditOrder->fresh(['account','authorizedUser','authorizedUsers','order','bill','logs']);
        });
    }

    private function assertAuthorizedUserLimit(CreditAccountUser $user, float $amount, bool $overrideLimit = false, bool $allowDailyLimitOverage = false): void
    {
        if ($overrideLimit) {
            return;
        }

        $dailyLimit = $user->daily_limit !== null ? round((float) $user->daily_limit, 2) : null;
        $monthlyLimit = $user->monthly_limit !== null ? round((float) $user->monthly_limit, 2) : null;

        $todayUsage = round((float) CreditOrderAuthorizedUser::where('credit_account_user_id', $user->id)
            ->whereDate('created_at', now()->toDateString())
            ->sum('allocated_amount'), 2);

        $monthUsage = round((float) CreditOrderAuthorizedUser::where('credit_account_user_id', $user->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('allocated_amount'), 2);

        if (!$allowDailyLimitOverage && $dailyLimit !== null && $dailyLimit > 0 && round($todayUsage + $amount, 2) > $dailyLimit) {
            throw new RuntimeException("Daily credit limit exceeded for {$user->full_name}. Limit: " . number_format($dailyLimit, 2) . ', used today: ' . number_format($todayUsage, 2));
        }

        if ($monthlyLimit !== null && $monthlyLimit > 0 && round($monthUsage + $amount, 2) > $monthlyLimit) {
            throw new RuntimeException("Monthly credit limit exceeded for {$user->full_name}. Limit: " . number_format($monthlyLimit, 2) . ', used this month: ' . number_format($monthUsage, 2));
        }
    }

    private function calculateDueDateFromAccount(CreditAccount $account)
    {
        return match ((string) ($account->settlement_cycle ?? 'monthly')) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            default => now()->addMonth(),
        };
    }

    public function settle(CreditOrder $creditOrder, array $data, int $userId): CreditOrder
    {
        return DB::transaction(function () use ($creditOrder, $data, $userId) {
            $creditOrder = CreditOrder::with(['account','bill'])->lockForUpdate()->findOrFail($creditOrder->id);
            if (!in_array($creditOrder->status, ['credit_approved','partially_settled','overdue'], true)) {
                throw new RuntimeException('Only approved, overdue, or partially settled credit orders can be settled.');
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0 || $amount > round((float) $creditOrder->remaining_amount, 2)) {
                throw new RuntimeException('Settlement amount is invalid or exceeds remaining balance.');
            }
            CreditSettlement::create([
                'credit_order_id' => $creditOrder->id,
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'received_by' => $userId,
                'settled_at' => $data['settled_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);
            $creditOrder->paid_amount = round((float) $creditOrder->paid_amount + $amount, 2);
            $creditOrder->remaining_amount = max(0, round((float) $creditOrder->total_amount - (float) $creditOrder->paid_amount, 2));
            $creditOrder->status = $creditOrder->remaining_amount <= 0 ? 'fully_settled' : 'partially_settled';
            $creditOrder->save();
            $creditOrder->account->current_balance = max(0, round((float) $creditOrder->account->current_balance - $amount, 2));
            $creditOrder->account->save();
            $creditOrder->bill->paid_amount = round((float) $creditOrder->bill->paid_amount + $amount, 2);
            $creditOrder->bill->balance = max(0, round((float) $creditOrder->bill->total - (float) $creditOrder->bill->paid_amount, 2));
            $creditOrder->bill->credit_status = $creditOrder->status;
            $creditOrder->bill->status = $creditOrder->bill->balance <= 0 ? 'paid' : 'partial';
            if ($creditOrder->bill->balance <= 0) $creditOrder->bill->paid_at = now();
            $creditOrder->bill->save();
            $creditOrder->order?->update(['credit_status' => $creditOrder->status]);
            CreditApprovalLog::create(['credit_order_id' => $creditOrder->id, 'action' => 'settled', 'actor_id' => $userId, 'note' => $data['notes'] ?? null]);
            return $creditOrder->fresh(['account','authorizedUser','authorizedUsers','order','bill','settlements.receiver','logs']);
        });
    }

    private function reference(): string
    {
        do { $ref = 'CR-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT); }
        while (CreditOrder::where('credit_reference', $ref)->exists());
        return $ref;
    }
}
