<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class InventoryDeductionService
{
    /**
     * Deduct inventory for a single confirmed order item and create OUT transactions.
     * Safe to call repeatedly because it checks inventory_deducted_at first.
     */
    public function deductForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem, $userId) {
            $lockedOrderItem = OrderItem::query()
                ->with(['menuItem'])
                ->lockForUpdate()
                ->findOrFail($orderItem->id);

            if ($lockedOrderItem->inventory_deducted_at) {
                return;
            }

            if ($lockedOrderItem->item_status !== 'confirmed') {
                return;
            }

            $recipe = Recipe::with('items.inventoryItem')
                ->where('menu_item_id', $lockedOrderItem->menu_item_id)
                ->first();

            if (! $recipe) {
                throw new \RuntimeException("No recipe found for menu item {$lockedOrderItem->menu_item_id}.");
            }

            if ($recipe->items->isEmpty()) {
                throw new \RuntimeException("Recipe for menu item {$lockedOrderItem->menu_item_id} has no ingredients.");
            }

            foreach ($recipe->items as $ri) {
                $inventoryItem = InventoryItem::query()->lockForUpdate()->find($ri->inventory_item_id);

                if (! $inventoryItem) {
                    throw new \RuntimeException("Recipe references missing inventory item #{$ri->inventory_item_id}.");
                }

                if (! empty($inventoryItem->unit) && ! empty($ri->unit) && $inventoryItem->unit !== $ri->unit) {
                    throw new \RuntimeException("Unit mismatch for {$inventoryItem->name}: recipe uses {$ri->unit}, inventory uses {$inventoryItem->unit}.");
                }

                $neededQty = round((float) $ri->quantity * (float) $lockedOrderItem->quantity, 3);
                if ($neededQty <= 0) {
                    continue;
                }

                if ((float) $inventoryItem->quantity < $neededQty) {
                    throw new \RuntimeException("Insufficient stock for {$inventoryItem->name}");
                }

                $inventoryItem->quantity = round((float) $inventoryItem->quantity - $neededQty, 3);
                $inventoryItem->save();

                InventoryTransaction::create([
                    'inventory_item_id' => $inventoryItem->id,
                    'type' => 'out',
                    'quantity' => $neededQty,
                    'unit_cost' => $inventoryItem->unit_cost,
                    'reference_type' => 'order_item',
                    'reference_id' => $lockedOrderItem->id,
                    'reason' => sprintf(
                        'Auto deduction on confirmed item for order #%s - %s',
                        $lockedOrderItem->order_id,
                        $lockedOrderItem->menuItem?->name ?? 'Unknown item'
                    ),
                    'created_by' => $userId,
                ]);
            }

            $lockedOrderItem->inventory_deducted_at = now();
            $lockedOrderItem->save();
        });
    }

    /**
     * Deduct inventory for all confirmed items in an order.
     */
    public function deductForOrder(Order $order, ?int $userId = null): void
    {
        $order->loadMissing(['items.menuItem']);

        foreach ($order->items as $orderItem) {
            if (in_array($orderItem->item_status, ['cancelled', 'rejected'], true)) {
                continue;
            }

            $this->deductForOrderItem($orderItem, $userId);
        }
    }
}
