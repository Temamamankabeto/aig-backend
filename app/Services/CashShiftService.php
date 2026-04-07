<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class CashShiftService
{
    public function open(int $userId, array $data): array
    {
        return DB::transaction(function () use ($userId, $data) {
            $exists = CashShift::where('cashier_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($exists) {
                throw new \RuntimeException('You already have an open shift');
            }

            $shift = CashShift::create([
                'cashier_id' => $userId,
                'status' => 'open',
                'opening_cash' => round((float) $data['opening_cash'], 2),
                'opened_at' => now(),
            ]);

            return $this->withSummary($shift);
        });
    }

    public function close(int $shiftId, array $data): array
    {
        return DB::transaction(function () use ($shiftId, $data) {
            $shift = CashShift::lockForUpdate()->findOrFail($shiftId);

            if ($shift->status !== 'open') {
                throw new \RuntimeException('Shift not open');
            }

            $summary = $this->summary($shift);
            $shift->update([
                'status' => 'closed',
                'closing_cash' => round((float) $data['closing_cash'], 2),
                'closed_at' => now(),
            ]);

            $fresh = $shift->fresh('cashier');

            return array_merge($fresh->toArray(), [
                'summary' => array_merge($summary, [
                    'variance' => round((float) $fresh->closing_cash - (float) $summary['expected_cash'], 2),
                ]),
            ]);
        });
    }

    public function withSummary(CashShift $shift): array
    {
        return array_merge(
            $shift->loadMissing('cashier')->toArray(),
            ['summary' => $this->summary($shift)]
        );
    }

    public function summary(CashShift $shift): array
    {
        $payments = Payment::where('cash_shift_id', $shift->id)
            ->where('status', 'paid');
    
        $cashPayments = (clone $payments)->where('method', 'cash')->sum('amount');
        $cardPayments = (clone $payments)->where('method', 'card')->sum('amount');
        $mobilePayments = (clone $payments)->where('method', 'mobile')->sum('amount');
        $transferPayments = (clone $payments)->where('method', 'transfer')->sum('amount');
    
        $totalPayments = $cashPayments + $cardPayments + $mobilePayments + $transferPayments;
    
        $movements = DB::table('cash_shift_movements')
            ->where('cash_shift_id', $shift->id);
    
        $openingAdjustments = (clone $movements)
            ->where('type', 'opening_adjustment')
            ->sum('amount');
    
        $cashRefunds = (clone $movements)
            ->where('type', 'refund')
            ->sum('amount');
    
        $paidOutExpenses = (clone $movements)
            ->where('type', 'paid_out')
            ->sum('amount');
    
        $cashDrops = (clone $movements)
            ->where('type', 'cash_drop')
            ->sum('amount');
    
        $expectedCash =
            (float) $shift->opening_cash
            + (float) $openingAdjustments
            + (float) $cashPayments
            - (float) $cashRefunds
            - (float) $paidOutExpenses
            - (float) $cashDrops;
    
        return [
            'payments_count' => (clone $payments)->count(),
    
            'cash_payments' => round((float) $cashPayments, 2),
            'card_payments' => round((float) $cardPayments, 2),
            'mobile_payments' => round((float) $mobilePayments, 2),
            'transfer_payments' => round((float) $transferPayments, 2),
            'total_payments' => round((float) $totalPayments, 2),
    
            'opening_adjustments' => round((float) $openingAdjustments, 2),
            'cash_refunds' => round((float) $cashRefunds, 2),
            'paid_out_expenses' => round((float) $paidOutExpenses, 2),
            'cash_drops' => round((float) $cashDrops, 2),
    
            'expected_cash' => round((float) $expectedCash, 2),
        ];
    }
}
