<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\StockReceiving;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReceivingController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private NotificationService $notificationService,
    ) {
    }

    public function receive(Request $request, $poId)
    {
        $this->authorize('receive', StockReceiving::class);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        return DB::transaction(function () use ($request, $poId, $data) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($poId);
            if ($po->status === 'received') {
                return response()->json(['success' => false, 'message' => 'PO has already been received'], 422);
            }
            if ($po->status === 'cancelled') {
                return response()->json(['success' => false, 'message' => 'Cancelled PO cannot be received'], 422);
            }
            if ($po->status !== 'approved') {
                return response()->json(['success' => false, 'message' => 'PO must be approved before receiving'], 422);
            }
            if ($po->items->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'PO has no items to receive'], 422);
            }

            $recv = StockReceiving::create([
                'purchase_order_id' => $po->id,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'note' => $data['note'] ?? null,
            ]);

            foreach ($po->items as $poi) {
                $inv = InventoryItem::lockForUpdate()->findOrFail($poi->inventory_item_id);
                $inv->quantity = (float) $inv->quantity + (float) $poi->quantity;
                $inv->unit_cost = (float) $poi->unit_cost;
                $inv->save();

                InventoryTransaction::create([
                    'inventory_item_id' => $inv->id,
                    'type' => 'in',
                    'quantity' => (float) $poi->quantity,
                    'unit_cost' => (float) $poi->unit_cost,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $po->id,
                    'reason' => 'Stock receiving',
                    'created_by' => $request->user()->id,
                ]);
            }

            $before = $po->toArray();
            $po->update(['status' => 'received', 'received_at' => now()]);
            $this->notificationService->notifyUsersByPermission('inventory.read', 'Stock received', "Purchase order {$po->po_number} has been received into inventory.", ['kind' => 'purchase_order_received', 'purchase_order_id' => $po->id]);
            $this->auditLogger->log($request, $request->user()->id, 'StockReceiving', $recv->id, 'stock_received', null, $recv->toArray(), 'Stock receiving recorded.');
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_received', $before, $po->fresh()->toArray(), 'Purchase order received.');

            return response()->json(['success' => true, 'data' => ['receiving' => $recv, 'po' => $po->fresh()->load('items')]]);
        });
    }
}
