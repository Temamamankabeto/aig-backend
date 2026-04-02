<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class BillService
{
    public function createOrUpdateDraft(int $orderId): Bill
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);

            $bill = Bill::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'status' => 'draft',
                    'subtotal' => $order->subtotal,
                    'tax' => $order->tax,
                    'service_charge' => $order->service_charge,
                    'discount' => $order->discount,
                    'total' => $order->total,
                    'paid_amount' => 0,
                    'balance' => $order->total,
                ]
            );

            if (in_array($bill->status, ['draft', 'issued', 'partial'], true)) {
                $bill->subtotal = $order->subtotal;
                $bill->tax = $order->tax;
                $bill->service_charge = $order->service_charge;
                $bill->discount = $order->discount;
                $bill->total = $order->total;
                $bill->balance = max(0, (float) $bill->total - (float) $bill->paid_amount);
                if ((float) $bill->balance <= 0) {
                    $bill->status = 'paid';
                } elseif ((float) $bill->paid_amount > 0) {
                    $bill->status = 'partial';
                }
                $bill->save();
            }

            return $bill->fresh('payments');
        });
    }
}
