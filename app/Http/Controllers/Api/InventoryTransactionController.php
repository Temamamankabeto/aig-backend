<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryTransactionController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $q = InventoryTransaction::query()->with('inventoryItem')->orderBy('id', 'desc');

        if ($request->filled('type')) $q->where('type', $request->type);
        if ($request->filled('reference_type')) $q->where('reference_type', $request->reference_type);
        if ($request->filled('inventory_item_id')) $q->where('inventory_item_id', $request->inventory_item_id);

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 30)))]);
    }

    public function adjust(Request $request, $itemId)
    {
        $this->authorize('adjust', InventoryTransaction::class);
        $data = $request->validate([
            'quantity' => 'required|numeric',
            'reason' => 'required|string|max:255',
            'expiry_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($itemId);
            $before = $item->toArray();
            $beforeQty = round((float) $item->current_stock, 3);
            $changeQty = round((float) $data['quantity'], 3);
            $newQty = round($beforeQty + $changeQty, 3);
            if ($newQty < 0) {
                return response()->json(['success' => false, 'message' => 'Resulting quantity cannot be negative'], 422);
            }

            $item->current_stock = $newQty;
            $item->save();

            if ($changeQty > 0) {
                InventoryItemBatch::create([
                    'inventory_item_id' => $item->id,
                    'purchase_price' => (float) ($item->average_purchase_price ?? 0),
                    'initial_qty' => $changeQty,
                    'remaining_qty' => $changeQty,
                    'expiry_date' => $data['expiry_date'] ?? null,
                ]);
            } elseif ($changeQty < 0) {
                $remaining = abs($changeQty);
                $batches = InventoryItemBatch::query()
                    ->where('inventory_item_id', $item->id)
                    ->where('remaining_qty', '>', 0)
                    ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('expiry_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $batch) {
                    if ($remaining <= 0) break;
                    $consume = min((float) $batch->remaining_qty, $remaining);
                    $batch->remaining_qty = round((float) $batch->remaining_qty - $consume, 3);
                    $batch->save();
                    $remaining = round($remaining - $consume, 3);
                }
            }

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'adjust',
                'quantity' => $changeQty,
                'unit_cost' => $item->average_purchase_price,
                'before_quantity' => $beforeQty,
                'after_quantity' => $newQty,
                'reference_type' => 'manual',
                'reference_id' => null,
                'note' => trim($data['reason'] . ' [' . $data['quantity'] . ' ' . $item->base_unit . ']'), 
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_adjusted', null, $tx->toArray(), 'Inventory adjusted manually.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_adjusted', $before, $item->fresh()->toArray(), 'Inventory item quantity adjusted.');

            return response()->json(['success' => true, 'data' => ['item' => $item->fresh(), 'tx' => $tx]]);
        });
    }

    public function waste(Request $request, $itemId)
    {
        $this->authorize('waste', InventoryTransaction::class);
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($itemId);
            $before = $item->toArray();
            $beforeQty = round((float) $item->current_stock, 3);
            $qty = round((float) $data['quantity'], 3);
            if ($beforeQty < $qty) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock for waste'], 422);
            }

            $item->current_stock = round($beforeQty - $qty, 3);
            $item->save();

            $remaining = $qty;
            $batches = InventoryItemBatch::query()
                ->where('inventory_item_id', $item->id)
                ->where('remaining_qty', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $consume = min((float) $batch->remaining_qty, $remaining);
                $batch->remaining_qty = round((float) $batch->remaining_qty - $consume, 3);
                $batch->save();
                $remaining = round($remaining - $consume, 3);
            }

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'out',
                'quantity' => $qty,
                'unit_cost' => $item->average_purchase_price,
                'before_quantity' => $beforeQty,
                'after_quantity' => (float) $item->current_stock,
                'reference_type' => 'waste',
                'reference_id' => null,
                'note' => trim($data['reason'] . ' [' . $data['quantity'] . ' ' . $item->base_unit . ']'), 
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_wasted', null, $tx->toArray(), 'Inventory waste recorded.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_wasted', $before, $item->fresh()->toArray(), 'Inventory item quantity reduced for waste.');

            return response()->json(['success' => true, 'data' => ['item' => $item->fresh(), 'tx' => $tx]]);
        });
    }


    public function transfer(Request $request, $itemId)
    {
        $this->authorize('adjust', InventoryTransaction::class);
        $data = $request->validate([
            'to_item_id' => 'required|different:itemId|exists:inventory_items,id',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $fromItem = InventoryItem::query()->lockForUpdate()->findOrFail($itemId);
            $toItem = InventoryItem::query()->lockForUpdate()->findOrFail($data['to_item_id']);

            $qtyFrom = round((float) $data['quantity'], 3);
            $qtyTo = $qtyFrom;

            $fromBefore = round((float) $fromItem->current_stock, 3);
            if ($fromBefore < $qtyFrom) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock for transfer'], 422);
            }

            $toBefore = round((float) $toItem->current_stock, 3);
            $fromAfter = round($fromBefore - $qtyFrom, 3);
            $toAfter = round($toBefore + $qtyTo, 3);

            $fromItem->current_stock = $fromAfter;
            $fromItem->save();

            $toItem->current_stock = $toAfter;
            $toItem->save();

            $referenceId = (string) now()->timestamp . random_int(100, 999);
            $note = trim($data['reason'] . ' [' . $data['quantity'] . ' ' . $fromItem->base_unit . ']');

            $outTx = InventoryTransaction::create([
                'inventory_item_id' => $fromItem->id,
                'type' => 'transfer_out',
                'quantity' => $qtyFrom,
                'unit_cost' => $fromItem->average_purchase_price,
                'before_quantity' => $fromBefore,
                'after_quantity' => $fromAfter,
                'reference_type' => 'transfer',
                'reference_id' => $referenceId,
                'note' => $note . ' -> ' . $toItem->name,
                'created_by' => $request->user()->id,
            ]);

            $inTx = InventoryTransaction::create([
                'inventory_item_id' => $toItem->id,
                'type' => 'transfer_in',
                'quantity' => $qtyTo,
                'unit_cost' => $toItem->average_purchase_price,
                'before_quantity' => $toBefore,
                'after_quantity' => $toAfter,
                'reference_type' => 'transfer',
                'reference_id' => $referenceId,
                'note' => $note . ' <- ' . $fromItem->name,
                'created_by' => $request->user()->id,
            ]);

            return response()->json(['success' => true, 'data' => [
                'from_item' => $fromItem->fresh(),
                'to_item' => $toItem->fresh(),
                'transactions' => [$outTx, $inTx],
            ]]);
        });
    }

}
