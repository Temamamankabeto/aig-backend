<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryDeductionService
{
    public function deductForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem, $userId) {
            $lockedOrderItem = OrderItem::query()
                ->with(['menuItem.directInventoryItem'])
                ->lockForUpdate()
                ->findOrFail($orderItem->id);

            if ($lockedOrderItem->inventory_deducted_at || $lockedOrderItem->item_status !== 'confirmed') {
                return;
            }

            $menuItem = $lockedOrderItem->menuItem;
            if (!$menuItem) {
                throw new RuntimeException('Order item menu item was not found.');
            }

            $trackingMode = $menuItem->inventory_tracking_mode
                ?? ($menuItem->has_ingredients ? 'recipe' : 'none');

            if ($trackingMode === 'none') {
                $lockedOrderItem->inventory_deducted_at = now();
                $lockedOrderItem->save();
                return;
            }

            if ($trackingMode === 'direct') {
                if (!$menuItem->direct_inventory_item_id) {
                    throw new RuntimeException("Direct inventory item is not linked for menu item {$menuItem->name}.");
                }

                $inventoryItem = InventoryItem::query()
                    ->lockForUpdate()
                    ->findOrFail($menuItem->direct_inventory_item_id);

                $neededQty = round((float) $lockedOrderItem->quantity, 3);

                $this->deductFromInventory(
                    $inventoryItem,
                    $neededQty,
                    'order_item',
                    $lockedOrderItem->id,
                    sprintf(
                        'Auto direct deduction on confirmed item for order #%s - %s',
                        $lockedOrderItem->order_id,
                        $menuItem->name
                    ),
                    $userId
                );

                $lockedOrderItem->inventory_deducted_at = now();
                $lockedOrderItem->save();
                return;
            }

            if ($trackingMode === 'recipe') {
                $recipe = Recipe::with('items.inventoryItem')
                    ->where('menu_item_id', $lockedOrderItem->menu_item_id)
                    ->first();

                if (!$recipe) {
                    throw new RuntimeException("Insufficient configuration: {$menuItem->name} has no recipe.");
                }

                if ($recipe->items->isEmpty()) {
                    throw new RuntimeException("Insufficient configuration: {$menuItem->name} recipe has no ingredients.");
                }

                foreach ($recipe->items as $ri) {
                    $inventoryItem = InventoryItem::query()
                        ->lockForUpdate()
                        ->find($ri->inventory_item_id);

                    if (!$inventoryItem) {
                        throw new RuntimeException("Recipe references missing inventory item #{$ri->inventory_item_id}.");
                    }

                    if (!empty($inventoryItem->unit) && !empty($ri->unit) && $inventoryItem->unit !== $ri->unit) {
                        throw new RuntimeException(
                            "Unit mismatch for {$inventoryItem->name}: recipe uses {$ri->unit}, inventory uses {$inventoryItem->unit}."
                        );
                    }

                    $neededQty = round((float) $ri->quantity * (float) $lockedOrderItem->quantity, 3);

                    if ($neededQty <= 0) {
                        continue;
                    }

                    $this->deductFromInventory(
                        $inventoryItem,
                        $neededQty,
                        'order_item',
                        $lockedOrderItem->id,
                        sprintf(
                            'Auto recipe deduction on confirmed item for order #%s - %s',
                            $lockedOrderItem->order_id,
                            $lockedOrderItem->menuItem?->name ?? 'Unknown item'
                        ),
                        $userId
                    );
                }

                $lockedOrderItem->inventory_deducted_at = now();
                $lockedOrderItem->save();
                return;
            }

            throw new RuntimeException("Unsupported inventory tracking mode for menu item {$menuItem->name}.");
        });
    }

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

    public function restoreForOrder(Order $order, ?int $userId = null): void
    {
        $order->loadMissing(['items.menuItem.recipe.items']);

        foreach ($order->items as $orderItem) {
            $this->restoreForOrderItem($orderItem, $userId);
        }
    }

    public function restoreForOrderItem(OrderItem $orderItem, ?int $userId = null): void
    {
        DB::transaction(function () use ($orderItem, $userId) {
            $lockedOrderItem = OrderItem::query()
                ->with(['menuItem.directInventoryItem'])
                ->lockForUpdate()
                ->findOrFail($orderItem->id);

            if (!$lockedOrderItem->inventory_deducted_at) {
                return;
            }

            $menuItem = $lockedOrderItem->menuItem;
            if (!$menuItem) {
                return;
            }

            $trackingMode = $menuItem->inventory_tracking_mode
                ?? ($menuItem->has_ingredients ? 'recipe' : 'none');

            if ($trackingMode === 'none') {
                $lockedOrderItem->inventory_deducted_at = null;
                $lockedOrderItem->save();
                return;
            }

            if ($trackingMode === 'direct' && $menuItem->direct_inventory_item_id) {
                $inventoryItem = InventoryItem::query()
                    ->lockForUpdate()
                    ->find($menuItem->direct_inventory_item_id);

                if ($inventoryItem) {
                    $this->restoreToInventory(
                        $inventoryItem,
                        round((float) $lockedOrderItem->quantity, 3),
                        'order_item_cancel',
                        $lockedOrderItem->id,
                        sprintf('Inventory restored from cancelled direct order item #%s', $lockedOrderItem->id),
                        $userId
                    );
                }
            }

            if ($trackingMode === 'recipe') {
                $recipe = Recipe::with('items')
                    ->where('menu_item_id', $lockedOrderItem->menu_item_id)
                    ->first();

                if ($recipe) {
                    foreach ($recipe->items as $ri) {
                        $inventoryItem = InventoryItem::query()
                            ->lockForUpdate()
                            ->find($ri->inventory_item_id);

                        if (!$inventoryItem) {
                            continue;
                        }

                        $restoreQty = round((float) $ri->quantity * (float) $lockedOrderItem->quantity, 3);

                        if ($restoreQty <= 0) {
                            continue;
                        }

                        $this->restoreToInventory(
                            $inventoryItem,
                            $restoreQty,
                            'order_item_cancel',
                            $lockedOrderItem->id,
                            sprintf('Inventory restored from cancelled recipe order item #%s', $lockedOrderItem->id),
                            $userId
                        );
                    }
                }
            }

            $lockedOrderItem->inventory_deducted_at = null;
            $lockedOrderItem->save();
        });
    }

    private function deductFromInventory(
        InventoryItem $inventoryItem,
        float $neededQty,
        string $referenceType,
        int $referenceId,
        string $note,
        ?int $userId
    ): void {
        if ((float) $inventoryItem->current_stock < $neededQty) {
            throw new RuntimeException("Insufficient stock for {$inventoryItem->name}");
        }

        $beforeQty = round((float) $inventoryItem->current_stock, 3);
        $afterQty = round($beforeQty - $neededQty, 3);

        $inventoryItem->current_stock = $afterQty;
        $inventoryItem->save();

        $remainingToConsume = $neededQty;

        $batches = InventoryItemBatch::query()
            ->where('inventory_item_id', $inventoryItem->id)
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consume = min((float) $batch->remaining_qty, $remainingToConsume);

            $batch->remaining_qty = round((float) $batch->remaining_qty - $consume, 3);
            $batch->save();

            $remainingToConsume = round($remainingToConsume - $consume, 3);
        }

        InventoryTransaction::create([
            'inventory_item_id' => $inventoryItem->id,
            'type' => 'out',
            'quantity' => $neededQty,
            'unit_cost' => $inventoryItem->average_purchase_price,
            'before_quantity' => $beforeQty,
            'after_quantity' => $afterQty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    private function restoreToInventory(
        InventoryItem $inventoryItem,
        float $qty,
        string $referenceType,
        int $referenceId,
        string $note,
        ?int $userId
    ): void {
        $beforeQty = round((float) $inventoryItem->current_stock, 3);
        $afterQty = round($beforeQty + $qty, 3);

        $inventoryItem->current_stock = $afterQty;
        $inventoryItem->save();

        InventoryTransaction::create([
            'inventory_item_id' => $inventoryItem->id,
            'type' => 'in',
            'quantity' => $qty,
            'unit_cost' => $inventoryItem->average_purchase_price,
            'before_quantity' => $beforeQty,
            'after_quantity' => $afterQty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }
}