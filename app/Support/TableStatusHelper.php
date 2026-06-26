<?php

namespace App\Support;

use App\Models\DiningTable;
use App\Models\Order;

class TableStatusHelper
{
    public static function operationalStatus(DiningTable $table): string
    {
        if (! $table->is_active) {
            return 'inactive';
        }

        $activeOrder = $table->relationLoaded('orders')
            ? $table->orders
                ->sortByDesc(fn ($order) => optional($order->ordered_at)->timestamp ?? optional($order->created_at)->timestamp ?? 0)
                ->first(fn ($order) => ! in_array($order->status, ['completed', 'cancelled', 'void'], true))
            : $table->orders()
                ->with('bill:id,order_id,status,balance')
                ->whereNotIn('status', ['completed', 'cancelled', 'void'])
                ->latest('ordered_at')
                ->latest('id')
                ->first();

        if ($activeOrder) {
            $bill = $activeOrder->relationLoaded('bill') ? $activeOrder->bill : $activeOrder->bill()->first();

            if ($bill) {
                if ((float) ($bill->balance ?? 0) > 0 && in_array($bill->status, ['issued', 'partial'], true)) {
                    return ((float) ($bill->paid_amount ?? 0) > 0) ? 'awaiting_payment' : 'awaiting_bill';
                }

                if (in_array($bill->status, ['draft'], true)) {
                    return 'awaiting_bill';
                }
            }

            return match ($activeOrder->status) {
                'pending' => 'pending_order',
                'confirmed', 'in_progress', 'ready', 'served' => 'order_in_progress',
                default => 'occupied',
            };
        }

        return $table->status ?: 'available';
    }

    public static function syncPhysicalStatus(DiningTable $table): void
    {
        if (! $table->is_active) {
            if ($table->status !== 'available') {
                $table->forceFill(['status' => 'available'])->save();
            }
            return;
        }

        $hasActiveOrder = Order::where('table_id', $table->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'void'])
            ->exists();

        $desired = $hasActiveOrder ? 'occupied' : 'available';

        if ($table->status !== $desired) {
            $table->forceFill(['status' => $desired])->save();
        }
    }
}
