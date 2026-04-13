<?php

namespace App\Services;

use App\Models\Order;

class OrderStatusService
{
    /**
     * Recalculate orders.status from order_items.item_status
     */
    public static function recalc(int $orderId): void
    {
        $order = Order::with('items')->find($orderId);

        if (! $order) {
            return;
        }

        if ($order->items->isEmpty()) {
            return;
        }

        $items = $order->items;

        // Ignore cancelled/rejected when calculating active workflow
        $activeItems = $items->filter(function ($item) {
            return ! in_array($item->item_status, ['cancelled', 'rejected'], true);
        });

        // If all items are cancelled/rejected
        if ($activeItems->isEmpty()) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
                $order->save();
            }
            return;
        }

        $allActiveIn = function (array $allowed) use ($activeItems) {
            return $activeItems->every(fn ($item) => in_array($item->item_status, $allowed, true));
        };

        $anyActiveIn = function (array $statuses) use ($activeItems) {
            return $activeItems->contains(fn ($item) => in_array($item->item_status, $statuses, true));
        };

        // 1) all active items served
        if ($allActiveIn(['served'])) {
            if ($order->status !== 'served') {
                $order->status = 'served';
                $order->served_at = $order->served_at ?? now();
                $order->save();
            }
            return;
        }

        // 2) all active items ready or served
        if ($allActiveIn(['ready', 'served'])) {
            if ($order->status !== 'ready') {
                $order->status = 'ready';
                $order->save();
            }
            return;
        }

        // 3) any active item preparing
        if ($anyActiveIn(['preparing'])) {
            if ($order->status !== 'in_progress') {
                $order->status = 'in_progress';
                $order->save();
            }
            return;
        }

        // 4) all active items confirmed
        if ($allActiveIn(['confirmed'])) {
            if ($order->status !== 'confirmed') {
                $order->status = 'confirmed';
                $order->confirmed_at = $order->confirmed_at ?? now();
                $order->save();
            }
            return;
        }

        // 5) fallback pending
        if ($order->status !== 'pending') {
            $order->status = 'pending';
            $order->save();
        }
    }
}