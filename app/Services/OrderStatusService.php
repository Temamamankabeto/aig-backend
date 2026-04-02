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
        if (!$order) return;

        if ($order->items->count() === 0) {
            // no items -> keep pending
            return;
        }

        $items = $order->items;

        $allIn = function(array $allowed) use ($items) {
            return $items->every(fn($i) => in_array($i->item_status, $allowed, true));
        };

        $anyIn = function(array $statuses) use ($items) {
            return $items->contains(fn($i) => in_array($i->item_status, $statuses, true));
        };

        // 1) all cancelled/rejected
        if ($allIn(['cancelled', 'rejected'])) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
                $order->save();
            }
            return;
        }

        // 2) all served (served or cancelled/rejected can be treated as done if you want)
        // strict served:
        if ($allIn(['served'])) {
            if ($order->status !== 'served') {
                $order->status = 'served';
                $order->save();
            }
            return;
        }

        // 3) all ready/served (order ready)
        if ($allIn(['ready', 'served'])) {
            if ($order->status !== 'ready') {
                $order->status = 'ready';
                $order->save();
            }
            return;
        }

        // 4) any preparing/delayed => in_progress
        if ($anyIn(['preparing', 'delayed'])) {
            if ($order->status !== 'in_progress') {
                $order->status = 'in_progress';
                $order->save();
            }
            return;
        }

        // 5) otherwise: keep confirmed if already confirmed, else pending
        // (example: all pending)
        if ($order->status === 'confirmed') return;

        if ($anyIn(['pending'])) {
            // stay pending unless confirmed manually
            if ($order->status !== 'pending') {
                $order->status = 'pending';
                $order->save();
            }
        }
    }
}