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
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $q = InventoryTransaction::query()->with('inventoryItem')->orderBy('id', 'desc');

        if ($request->filled('type')) $q->where('type', $request->type);
        if ($request->filled('inventory_item_id')) $q->where('inventory_item_id', $request->inventory_item_id);

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 30)))]);
    }

    public function adjust(Request $request, $itemId)
    {
        $this->authorize('adjust', InventoryTransaction::class);
        $data = $request->validate(['quantity' => 'required|numeric', 'reason' => 'required|string|max:255']);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::lockForUpdate()->findOrFail($itemId);
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
                    'expiry_date' => null,
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
                'note' => $data['reason'],
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
        $data = $request->validate(['quantity' => 'required|numeric|min:0.001', 'reason' => 'required|string|max:255']);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::lockForUpdate()->findOrFail($itemId);
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
                'note' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_wasted', null, $tx->toArray(), 'Inventory waste recorded.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_wasted', $before, $item->fresh()->toArray(), 'Inventory item quantity reduced for waste.');

            return response()->json(['success' => true, 'data' => ['item' => $item->fresh(), 'tx' => $tx]]);
        });
    }
}
