<?php

namespace App\Services;

use App\Models\Bill;
use Illuminate\Http\Request;

class OrderSettlementService
{
    public function __construct(
        private InventoryDeductionService $inventoryDeductionService,
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function settlePaidBill(Bill $bill, ?Request $request = null, ?int $actorId = null, string $source = 'payment'): void
    {
        $bill->loadMissing('order.table', 'payments');
        $order = $bill->order;

        if (!$order) {
            return;
        }

        $fullyPaid = round((float) $bill->balance, 2) <= 0 && in_array($bill->status, ['paid'], true);
        if (!$fullyPaid) {
            return;
        }

        if (in_array($order->status, ['served', 'delivered', 'ready', 'confirmed', 'in_progress'], true)) {
            $before = $order->toArray();

            if ($order->status !== 'completed') {
                $order->status = 'completed';
                $order->completed_at = now();
                $order->save();
            }

            if ($order->order_type === 'dine_in' && $order->table_id && $order->table && $order->table->status !== 'available') {
                $order->table->update(['status' => 'available']);
            }

            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Order completed',
                "Order {$order->order_number} is fully paid and completed.",
                ['kind' => 'order_completed', 'order_id' => $order->id, 'bill_id' => $bill->id, 'source' => $source]
            );

            $this->auditLogger->log(
                $request,
                $actorId,
                'Order',
                $order->id,
                'order_completed',
                $before,
                $order->fresh()->toArray(),
                'Order automatically completed after full payment.'
            );
        } else {
            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Bill fully paid',
                "Bill for order {$order->order_number} is fully paid.",
                ['kind' => 'bill_paid', 'order_id' => $order->id, 'bill_id' => $bill->id, 'source' => $source]
            );
        }
    }
}
