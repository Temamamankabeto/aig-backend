<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                Log::info('Skipping inventory deduction for order item.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'inventory_deducted_at' => $lockedOrderItem->inventory_deducted_at,
                    'item_status' => $lockedOrderItem->item_status,
                ]);
                return;
            }

            $menuItem = $lockedOrderItem->menuItem;
            if (!$menuItem) {
                throw new RuntimeException('Order item menu item was not found.');
            }

            $trackingMode = $menuItem->inventory_tracking_mode
                ?? ($menuItem->has_ingredients ? 'recipe' : 'none');

            Log::info('Inventory deduction started for order item.', [
                'order_item_id' => $lockedOrderItem->id,
                'order_id' => $lockedOrderItem->order_id,
                'menu_item_id' => $menuItem->id,
                'menu_item_name' => $menuItem->name,
                'tracking_mode' => $trackingMode,
                'quantity' => $lockedOrderItem->quantity,
            ]);

            if ($trackingMode === 'none') {
                Log::info('No inventory deduction needed for service-only item.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'menu_item_name' => $menuItem->name,
                ]);

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

                Log::info('Direct inventory deduction detected.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'inventory_item_id' => $inventoryItem->id,
                    'inventory_item_name' => $inventoryItem->name,
                    'needed_qty' => $neededQty,
                ]);

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

                Log::info('Direct inventory deduction completed.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'inventory_item_id' => $inventoryItem->id,
                ]);

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

                Log::info('Recipe-based deduction detected.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'menu_item_name' => $menuItem->name,
                    'recipe_id' => $recipe->id,
                    'ingredients_count' => $recipe->items->count(),
                ]);

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

                    Log::info('Preparing ingredient deduction.', [
                        'order_item_id' => $lockedOrderItem->id,
                        'ingredient_inventory_item_id' => $ri->inventory_item_id,
                        'ingredient_inventory_item_name' => $inventoryItem->name,
                        'recipe_unit_qty' => (float) $ri->quantity,
                        'order_quantity' => (float) $lockedOrderItem->quantity,
                        'total_needed_qty' => $neededQty,
                        'recipe_unit' => $ri->unit,
                        'inventory_unit' => $inventoryItem->unit,
                    ]);

                    if ($neededQty <= 0) {
                        Log::info('Skipping ingredient deduction because needed quantity is zero or negative.', [
                            'order_item_id' => $lockedOrderItem->id,
                            'ingredient_inventory_item_id' => $ri->inventory_item_id,
                            'total_needed_qty' => $neededQty,
                        ]);
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

                Log::info('Recipe-based deduction completed.', [
                    'order_item_id' => $lockedOrderItem->id,
                    'menu_item_name' => $menuItem->name,
                ]);

                return;
            }

            throw new RuntimeException("Unsupported inventory tracking mode for menu item {$menuItem->name}.");
        });
    }

    public function deductForOrder(Order $order, ?int $userId = null): void
    {
        $order->loadMissing(['items.menuItem']);

        Log::info('Deducting inventory for full order.', [
            'order_id' => $order->id,
            'items_count' => $order->items->count(),
        ]);

        foreach ($order->items as $orderItem) {
            if (in_array($orderItem->item_status, ['cancelled', 'rejected'], true)) {
                Log::info('Skipping deduction for cancelled/rejected order item.', [
                    'order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'item_status' => $orderItem->item_status,
                ]);
                continue;
            }

            $this->deductForOrderItem($orderItem, $userId);
        }
    }

    public function restoreForOrder(Order $order, ?int $userId = null): void
    {
        $order->loadMissing(['items.menuItem.recipe.items']);

        Log::info('Restoring inventory for full order.', [
            'order_id' => $order->id,
            'items_count' => $order->items->count(),
        ]);

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
                Log::info('Skipping inventory restore because item was not deducted.', [
                    'order_item_id' => $lockedOrderItem->id,
                ]);
                return;
            }

            $menuItem = $lockedOrderItem->menuItem;
            if (!$menuItem) {
                Log::warning('Skipping inventory restore because menu item was not found.', [
                    'order_item_id' => $lockedOrderItem->id,
                ]);
                return;
            }

            $trackingMode = $menuItem->inventory_tracking_mode
                ?? ($menuItem->has_ingredients ? 'recipe' : 'none');

            Log::info('Inventory restore started for order item.', [
                'order_item_id' => $lockedOrderItem->id,
                'order_id' => $lockedOrderItem->order_id,
                'menu_item_name' => $menuItem->name,
                'tracking_mode' => $trackingMode,
            ]);

            if ($trackingMode === 'none') {
                $lockedOrderItem->inventory_deducted_at = null;
                $lockedOrderItem->save();

                Log::info('No stock restore needed for service-only item.', [
                    'order_item_id' => $lockedOrderItem->id,
                ]);
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

            Log::info('Inventory restore completed for order item.', [
                'order_item_id' => $lockedOrderItem->id,
            ]);
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
        Log::info('Checking stock availability before deduction.', [
            'inventory_item_id' => $inventoryItem->id,
            'inventory_item_name' => $inventoryItem->name,
            'current_stock' => (float) $inventoryItem->current_stock,
            'needed_qty' => $neededQty,
        ]);

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

            Log::info('Consumed stock from batch.', [
                'inventory_item_id' => $inventoryItem->id,
                'batch_id' => $batch->id,
                'consumed_qty' => $consume,
                'batch_remaining_qty' => (float) $batch->remaining_qty,
                'remaining_to_consume' => $remainingToConsume,
            ]);
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

        Log::info('Stock deducted successfully.', [
            'inventory_item_id' => $inventoryItem->id,
            'inventory_item_name' => $inventoryItem->name,
            'before_quantity' => $beforeQty,
            'after_quantity' => $afterQty,
            'deducted_qty' => $neededQty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
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

        Log::info('Stock restored successfully.', [
            'inventory_item_id' => $inventoryItem->id,
            'inventory_item_name' => $inventoryItem->name,
            'before_quantity' => $beforeQty,
            'after_quantity' => $afterQty,
            'restored_qty' => $qty,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}