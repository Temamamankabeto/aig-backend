<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Recipe;

class InventoryDeductionService
{
    /**
     * Deduct inventory for an order and create OUT transactions.
     * This method is intended to be called only once per completed order.
     */
    public function deductForOrder(Order $order): void
    {
        if (InventoryTransaction::where('reference_type', 'order')->where('reference_id', $order->id)->exists()) {
            return;
        }

        $order->load(['items.menuItem']);

        foreach ($order->items as $orderItem) {
            if (in_array($orderItem->item_status, ['cancelled', 'rejected'], true)) {
                continue;
            }

            $recipe = Recipe::with('items')->where('menu_item_id', $orderItem->menu_item_id)->first();
            if (! $recipe) {
                continue;
            }
            if ($recipe->items->isEmpty()) {
                throw new \RuntimeException("Recipe for menu item {$orderItem->menu_item_id} has no ingredients.");
            }

            foreach ($recipe->items as $ri) {
                $inventoryItem = InventoryItem::lockForUpdate()->find($ri->inventory_item_id);
                if (! $inventoryItem) {
                    throw new \RuntimeException("Recipe references missing inventory item #{$ri->inventory_item_id}.");
                }

                $neededQty = (float) $ri->quantity * (float) $orderItem->quantity;
                if ((float) $inventoryItem->quantity < $neededQty) {
                    throw new \RuntimeException("Insufficient stock for {$inventoryItem->name}");
                }

                $inventoryItem->quantity = (float) $inventoryItem->quantity - $neededQty;
                $inventoryItem->save();

                InventoryTransaction::create([
                    'inventory_item_id' => $inventoryItem->id,
                    'type' => 'out',
                    'quantity' => $neededQty,
                    'unit_cost' => $inventoryItem->unit_cost,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'reason' => 'Auto deduction at COMPLETED',
                    'created_by' => null,
                ]);
            }
        }
    }
}
