<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
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
        $q = InventoryTransaction::query()->with('item')->orderBy('id', 'desc');

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
            $newQty = (float) $item->quantity + (float) $data['quantity'];
            if ($newQty < 0) {
                return response()->json(['success' => false, 'message' => 'Resulting quantity cannot be negative'], 422);
            }

            $item->quantity = $newQty;
            $item->save();

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'adjust',
                'quantity' => (float) $data['quantity'],
                'unit_cost' => $item->unit_cost,
                'reference_type' => 'manual',
                'reference_id' => null,
                'reason' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_adjusted', null, $tx->toArray(), 'Inventory adjusted manually.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_adjusted', $before, $item->fresh()->toArray(), 'Inventory item quantity adjusted.');

            return response()->json(['success' => true, 'data' => ['item' => $item, 'tx' => $tx]]);
        });
    }

    public function waste(Request $request, $itemId)
    {
        $this->authorize('waste', InventoryTransaction::class);
        $data = $request->validate(['quantity' => 'required|numeric|min:0.001', 'reason' => 'required|string|max:255']);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::lockForUpdate()->findOrFail($itemId);
            $before = $item->toArray();
            $qty = (float) $data['quantity'];
            if ((float) $item->quantity < $qty) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock for waste'], 422);
            }

            $item->quantity = (float) $item->quantity - $qty;
            $item->save();

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'waste',
                'quantity' => $qty,
                'unit_cost' => $item->unit_cost,
                'reference_type' => 'waste',
                'reference_id' => null,
                'reason' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_wasted', null, $tx->toArray(), 'Inventory waste recorded.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_wasted', $before, $item->fresh()->toArray(), 'Inventory item quantity reduced for waste.');

            return response()->json(['success' => true, 'data' => ['item' => $item, 'tx' => $tx]]);
        });
    }
}
