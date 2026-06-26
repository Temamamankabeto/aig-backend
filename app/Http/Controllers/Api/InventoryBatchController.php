<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItemBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryItemBatch::query()
            ->with(['inventoryItem:id,name,sku,base_unit,current_stock,minimum_quantity'])
            ->when($request->filled('inventory_item_id'), function ($q) use ($request) {
                $q->where('inventory_item_id', $request->integer('inventory_item_id'));
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->string('status')->toString();

                if ($status === 'expired') {
                    $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
                } elseif ($status === 'active') {
                    $q->where('remaining_qty', '>', 0)
                        ->where(function ($sub) {
                            $sub->whereNull('expiry_date')
                                ->orWhereDate('expiry_date', '>=', now()->toDateString());
                        });
                } elseif ($status === 'empty') {
                    $q->where('remaining_qty', '<=', 0);
                }
            })
            ->latest();

        $perPage = min((int) $request->get('per_page', 50), 100);
        $batches = $query->paginate($perPage);

        $batches->getCollection()->transform(function (InventoryItemBatch $batch) {
            $expired = $batch->expiry_date && $batch->expiry_date->isPast();
            $empty = (float) $batch->remaining_qty <= 0;

            return [
                'id' => $batch->id,
                'inventory_item_id' => $batch->inventory_item_id,
                'batch_no' => 'BATCH-' . str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT),
                'purchase_price' => $batch->purchase_price,
                'initial_qty' => $batch->initial_qty,
                'remaining_qty' => $batch->remaining_qty,
                'remaining_quantity' => $batch->remaining_qty,
                'expiry_date' => optional($batch->expiry_date)->toDateString(),
                'status' => $empty ? 'empty' : ($expired ? 'expired' : 'active'),
                'created_at' => optional($batch->created_at)->toISOString(),
                'updated_at' => optional($batch->updated_at)->toISOString(),
                'inventory_item' => $batch->inventoryItem ? [
                    'id' => $batch->inventoryItem->id,
                    'name' => $batch->inventoryItem->name,
                    'sku' => $batch->inventoryItem->sku,
                    'base_unit' => $batch->inventoryItem->base_unit,
                    'current_stock' => $batch->inventoryItem->current_stock,
                    'minimum_quantity' => $batch->inventoryItem->minimum_quantity,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }
}
