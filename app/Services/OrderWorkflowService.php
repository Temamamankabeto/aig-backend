<?php

namespace App\Services;

use App\Models\Order;

class OrderWorkflowService
{
    public function refreshOrderStatusFromTickets(Order $order): void
    {
        $order->load([
            'items.kitchenTicket',
            'items.barTicket',
        ]);

        $tickets = collect();

        foreach ($order->items as $item) {
            if ($item->station === 'kitchen' && $item->kitchenTicket) {
                $tickets->push($item->kitchenTicket);
            }

            if ($item->station === 'bar' && $item->barTicket) {
                $tickets->push($item->barTicket);
            }
        }

        if ($tickets->isEmpty()) {
            return;
        }

        $allReady = $tickets->every(fn ($t) => $t->status === 'ready');
        $anyPreparing = $tickets->contains(fn ($t) => in_array($t->status, ['preparing', 'delayed'], true));
        $anyRejected = $tickets->contains(fn ($t) => $t->status === 'rejected');

        if ($allReady) {
            $order->update(['status' => 'ready']);
            return;
        }

        if ($anyRejected) {
            $order->update(['status' => 'issue']);
            return;
        }

        if ($anyPreparing) {
            $order->update(['status' => 'in_progress']);
            return;
        }
    }
}