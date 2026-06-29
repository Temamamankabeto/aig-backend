<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class InventoryDeductionService
{
    /**
     * Inventory deduction has intentionally been disabled for order management.
     *
     * Recipe Management and Inventory Management remain available as independent modules,
     * but customer orders no longer reduce inventory stock automatically.
     *
     * This method name is kept for backward compatibility with existing controllers/services.
     * It only confirms the order item status and does not touch inventory quantities,
     * batches, or inventory transactions.
     */
    public function confirmAndDeductForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem) {
            $lockedOrderItem = OrderItem::query()
                ->lockForUpdate()
                ->findOrFail($orderItem->id);

            if ($lockedOrderItem->item_status !== 'confirmed') {
                $lockedOrderItem->item_status = 'confirmed';
                $lockedOrderItem->save();
            }
        });
    }

    /**
     * No-op: orders must not deduct inventory.
     */
    public function deductForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        return;
    }

    /**
     * No-op: orders must not deduct inventory.
     */
    public function deductForOrder(Order $order, ?int $userId = null): void
    {
        return;
    }

    /**
     * No-op: because orders no longer deduct inventory, cancellation/voiding orders
     * must not restore inventory either.
     */
    public function restoreForOrder(Order $order, ?int $userId = null): void
    {
        return;
    }

    /**
     * No-op: because orders no longer deduct inventory, item cancellation/rejection
     * must not restore inventory either.
     */
    public function restoreForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        return;
    }
}
