<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private InventoryDeductionService $inventoryDeductionService,
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function storePayment(int $billId, array $data, int $userId): array
    {
        return DB::transaction(function () use ($billId, $data, $userId) {
            $bill = Bill::lockForUpdate()->findOrFail($billId);

            if ($bill->status === 'void') {
                throw new \RuntimeException('Bill is void');
            }

            $shiftId = $data['cash_shift_id'] ?? null;

            if ($shiftId) {
                $shift = CashShift::lockForUpdate()->findOrFail($shiftId);
            } else {
                $shift = CashShift::where('cashier_id', $userId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
            }

            if (! $shift || $shift->status !== 'open') {
                throw new \RuntimeException('An open cash shift is required for payments');
            }

            if ((int) $shift->cashier_id !== (int) $userId) {
                throw new \RuntimeException('You can only record payments on your own open shift');
            }

            $shiftId = $shift->id;

            $amount = round((float) $data['amount'], 2);
            $balance = round((float) $bill->balance, 2);

            if ($amount > $balance) {
                throw new \RuntimeException('Payment exceeds remaining balance');
            }

            $payment = Payment::create([
                'bill_id' => $bill->id,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'status' => 'paid',
                'received_by' => $userId,
                'cash_shift_id' => $shiftId,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $bill->paid_amount = round((float) $bill->paid_amount + $amount, 2);
            $bill->balance = max(0, round((float) $bill->total - (float) $bill->paid_amount, 2));

            if ($bill->issued_at === null) {
                $bill->issued_by = $userId;
                $bill->issued_at = now();
            }

            $bill->status = (float) $bill->balance <= 0 ? 'paid' : 'partial';

            if ($bill->status === 'paid') {
                $bill->paid_at = now();
            }

            $bill->save();
            $this->finalizeOrderIfEligible($bill);

            $this->auditLogger->log(request(), $userId, 'Payment', $payment->id, 'payment_recorded', null, $payment->toArray(), 'Payment recorded successfully.');

            return [
                'payment' => $payment->fresh(['receiver']),
                'bill' => $bill->fresh(['payments']),
            ];
        });
    }

    public function submitByWaiter(array $data, int $userId, ?UploadedFile $receipt = null): array
    {
        return DB::transaction(function () use ($data, $userId, $receipt) {
            $bill = Bill::lockForUpdate()->findOrFail($data['bill_id']);
            $order = Order::findOrFail($bill->order_id);

            if ($bill->status === 'void') {
                throw new \RuntimeException('Bill is void');
            }

            if (! in_array($order->status, ['served', 'delivered'], true)) {
                throw new \RuntimeException('Only served or delivered orders can submit payment.');
            }

            $amount = round((float) $data['amount'], 2);
            $balance = round((float) $bill->balance, 2);

            if ($amount > $balance) {
                throw new \RuntimeException('Submitted amount exceeds remaining balance');
            }

            if (($data['method'] ?? null) === 'transfer' && empty($data['reference'])) {
                throw new \RuntimeException('Transfer reference is required');
            }

            $existingSubmitted = Payment::where('bill_id', $bill->id)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->exists();

            if ($existingSubmitted) {
                throw new \RuntimeException('A payment for this bill is already submitted and waiting for approval.');
            }

            $receiptPath = $receipt ? $receipt->store('payments/receipts', 'public') : null;

            $paymentData = [
                'bill_id' => $bill->id,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'receipt_path' => $receiptPath,
                'status' => 'submitted',
                'received_by' => $userId,
                'cash_shift_id' => null,
                'paid_at' => $data['paid_at'] ?? now(),
            ];

            if (array_key_exists('note', $data)) {
                $paymentData['note'] = $data['note'];
            }

            $payment = Payment::create($paymentData);

            $this->notificationService->notifyUsersByPermission(
                'cashier.dashboard',
                'Payment submitted',
                "Payment submitted for bill #{$bill->id} and awaiting review.",
                ['kind' => 'payment_submitted', 'bill_id' => $bill->id, 'payment_id' => $payment->id, 'order_id' => $order->id]
            );
            $this->notificationService->notifyUsersByPermission(
                'finance.dashboard',
                'Payment submitted',
                "Payment submitted for bill #{$bill->id} and awaiting finance visibility.",
                ['kind' => 'payment_submitted', 'bill_id' => $bill->id, 'payment_id' => $payment->id, 'order_id' => $order->id]
            );

            $this->auditLogger->log(request(), $userId, 'Payment', $payment->id, 'payment_submitted', null, $payment->toArray(), 'Waiter submitted payment for approval.');

            return [
                'payment' => $payment,
                'bill' => $bill->fresh(['payments']),
            ];
        });
    }

    public function approve(int $paymentId, int $userId): array
    {
        return DB::transaction(function () use ($paymentId, $userId) {
            $payment = Payment::with(['bill'])->lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== 'submitted') {
                throw new \RuntimeException('Only submitted payments can be approved');
            }

            $bill = Bill::lockForUpdate()->findOrFail($payment->bill_id);

            $amount = round((float) $payment->amount, 2);
            $newPaidAmount = round((float) $bill->paid_amount + $amount, 2);
            $newBalance = max(0, round((float) $bill->total - $newPaidAmount, 2));

            $shift = CashShift::where('cashier_id', $userId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $shift || $shift->status !== 'open') {
                throw new \RuntimeException('An open cash shift is required to approve payments');
            }

            $before = $payment->toArray();
            $payment->update([
                'status' => 'paid',
                'cash_shift_id' => $shift->id,
            ]);

            if ($bill->issued_at === null) {
                $bill->issued_by = $userId;
                $bill->issued_at = now();
            }

            $bill->paid_amount = $newPaidAmount;
            $bill->balance = $newBalance;
            $bill->status = $newBalance <= 0 ? 'paid' : 'partial';

            if ($bill->status === 'paid') {
                $bill->paid_at = now();
            }

            $bill->save();
            $this->finalizeOrderIfEligible($bill);

            $orderId = $bill->order_id;
            $waiterId = Order::whereKey($orderId)->value('waiter_id');
            $this->notificationService->notifyUser(
                $waiterId,
                'Payment approved',
                "Payment for bill #{$bill->id} has been approved.",
                ['kind' => 'payment_approved', 'bill_id' => $bill->id, 'payment_id' => $payment->id, 'order_id' => $orderId]
            );

            $this->auditLogger->log(request(), $userId, 'Payment', $payment->id, 'payment_approved', $before, $payment->fresh()->toArray(), 'Submitted payment approved.', ['approved_by' => $userId, 'approved_at' => now()]);

            return [
                'payment' => $payment->fresh(['receiver']),
                'bill' => $bill->fresh(['payments']),
            ];
        });
    }

    public function returnPayment(int $paymentId): Payment
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = Payment::lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== 'submitted') {
                throw new \RuntimeException('Only submitted payments can be returned');
            }

            $before = $payment->toArray();
            $payment->update([
                'status' => 'returned',
            ]);

            $bill = Bill::find($payment->bill_id);
            $waiterId = $bill?->order?->waiter_id;
            $this->notificationService->notifyUser(
                $waiterId,
                'Payment returned',
                "Payment for bill #{$payment->bill_id} was returned for correction.",
                ['kind' => 'payment_returned', 'bill_id' => $payment->bill_id, 'payment_id' => $payment->id, 'order_id' => $bill?->order_id]
            );
            $this->auditLogger->log(request(), auth()->id(), 'Payment', $payment->id, 'payment_returned', $before, $payment->fresh()->toArray(), 'Submitted payment returned for correction.');

            return $payment->fresh();
        });
    }

    public function failPayment(int $paymentId): Payment
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = Payment::lockForUpdate()->findOrFail($paymentId);

            if ($payment->status !== 'submitted') {
                throw new \RuntimeException('Only submitted payments can be marked failed');
            }

            $before = $payment->toArray();
            $payment->update([
                'status' => 'failed',
            ]);

            $bill = Bill::find($payment->bill_id);
            $waiterId = $bill?->order?->waiter_id;
            $this->notificationService->notifyUser(
                $waiterId,
                'Payment failed',
                "Payment for bill #{$payment->bill_id} was marked failed.",
                ['kind' => 'payment_failed', 'bill_id' => $payment->bill_id, 'payment_id' => $payment->id, 'order_id' => $bill?->order_id]
            );
            $this->auditLogger->log(request(), auth()->id(), 'Payment', $payment->id, 'payment_failed', $before, $payment->fresh()->toArray(), 'Submitted payment marked failed.');

            return $payment->fresh();
        });
    }

    private function finalizeOrderIfEligible(Bill $bill): void
    {
        if ($bill->status !== 'paid') {
            return;
        }

        $order = Order::lockForUpdate()->find($bill->order_id);
        if (! $order) {
            return;
        }

        if (! in_array($order->status, ['served', 'delivered', 'ready', 'completed'], true)) {
            return;
        }

        if ($order->status !== 'completed') {
            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
