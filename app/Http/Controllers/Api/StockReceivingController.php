<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderStatusHistory;
use App\Models\StockReceiving;
use App\Models\StockReceivingItem;
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

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockReceiving::class);
        $q = StockReceiving::query()->with(['purchaseOrder.supplier', 'items.inventoryItem', 'receiver'])->latest('id');

        if ($request->filled('purchase_order_id')) {
            $q->where('purchase_order_id', $request->integer('purchase_order_id'));
        }
        if ($request->filled('supplier_id')) {
            $supplierId = $request->integer('supplier_id');
            $q->whereHas('purchaseOrder', fn ($sub) => $sub->where('supplier_id', $supplierId));
        }
        if ($request->filled('inventory_item_id')) {
            $inventoryItemId = $request->integer('inventory_item_id');
            $q->whereHas('items', fn ($sub) => $sub->where('inventory_item_id', $inventoryItemId));
        }

        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }

    public function show($id)
    {
        $receiving = StockReceiving::with(['purchaseOrder.supplier', 'items.inventoryItem', 'receiver'])->findOrFail($id);
        $this->authorize('view', $receiving);
        return response()->json(['success' => true, 'data' => $receiving]);
    }

    public function receive(Request $request, $poId)
    {
        $this->authorize('receive', StockReceiving::class);

        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
            'delivery_note' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
            'delivery_note_path' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.batch_note' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $poId, $data) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($poId);

            if ($po->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cancelled PO cannot be received',
                ], 422);
            }

            if (! in_array($po->status, ['approved', 'partially_received'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'PO must be approved before receiving',
                ], 422);
            }

            if ($po->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'PO has no items to receive',
                ], 422);
            }

            $deliveryNotePath = $data['delivery_note_path'] ?? null;
            if ($request->hasFile('delivery_note')) {
                $deliveryNotePath = $request->file('delivery_note')->store('deliveries', 'public');
            }

            $recv = StockReceiving::create([
                'purchase_order_id' => $po->id,
                'status' => 'approved',
                'received_by' => $request->user()->id,
                'approved_by' => $request->user()->id,
                'received_at' => now(),
                'approved_at' => now(),
                'note' => $data['note'] ?? null,
                'delivery_note_path' => $deliveryNotePath,
            ]);

            $createdBatches = [];
            $createdTransactions = [];

            foreach ($data['items'] as $line) {
                $poi = $po->items->firstWhere('id', $line['purchase_order_item_id']);

                if (! $poi) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected item does not belong to the purchase order.',
                    ], 422);
                }

                $inv = InventoryItem::lockForUpdate()->findOrFail($poi->inventory_item_id);

                $remainingQty = round(
                    (float) $poi->quantity - (float) ($poi->received_quantity ?? 0),
                    3
                );

                $receivedQty = round((float) $line['quantity'], 3);

                if ($receivedQty <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => "Received quantity must be greater than zero for item #{$poi->id}.",
                    ], 422);
                }

                if ($receivedQty > $remainingQty) {
                    return response()->json([
                        'success' => false,
                        'message' => "Received quantity cannot exceed remaining quantity for item #{$poi->id}.",
                    ], 422);
                }

                $beforeQty = round((float) $inv->current_stock, 3);
                $afterQty = round($beforeQty + $receivedQty, 3);

                $oldAvg = round((float) ($inv->average_purchase_price ?? 0), 4);
                $incomingUnitCost = round((float) $poi->unit_cost, 4);

                $oldValue = round($beforeQty * $oldAvg, 4);
                $incomingValue = round($receivedQty * $incomingUnitCost, 4);

                $newAvg = $afterQty > 0
                    ? round(($oldValue + $incomingValue) / $afterQty, 4)
                    : $incomingUnitCost;

                $beforeInventoryItem = $inv->toArray();
                $inv->current_stock = $afterQty;
                $inv->average_purchase_price = $newAvg;
                $inv->save();

                $batch = InventoryItemBatch::create([
                    'inventory_item_id' => $inv->id,
                    'purchase_price' => $incomingUnitCost,
                    'initial_qty' => $receivedQty,
                    'remaining_qty' => $receivedQty,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);
                $createdBatches[] = $batch;

                $receivingItem = StockReceivingItem::create([
                    'stock_receiving_id' => $recv->id,
                    'purchase_order_item_id' => $poi->id,
                    'inventory_item_id' => $inv->id,
                    'quantity_received' => $receivedQty,
                    'base_unit' => $inv->base_unit,
                    'unit_cost' => $incomingUnitCost,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'batch_note' => $line['batch_note'] ?? null,
                ]);

                $poi->received_quantity = round(
                    (float) ($poi->received_quantity ?? 0) + $receivedQty,
                    3
                );
                $poi->save();

                $transactionNote = sprintf(
                    'Stock received from PO %s. Receiving #%s, receiving item #%s, batch BATCH-%s.',
                    $po->po_number ?? $po->id,
                    $recv->id,
                    $receivingItem->id,
                    str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT)
                );

                if (! empty($line['batch_note'])) {
                    $transactionNote .= ' Note: ' . $line['batch_note'];
                }

                $tx = InventoryTransaction::create([
                    'inventory_item_id' => $inv->id,
                    'type' => 'in',
                    'quantity' => $receivedQty,
                    'unit_cost' => $incomingUnitCost,
                    'before_quantity' => $beforeQty,
                    'after_quantity' => $afterQty,
                    'reference_type' => 'purchase_receive',
                    'reference_id' => $recv->id,
                    'note' => $transactionNote,
                    'created_by' => $request->user()->id,
                ]);
                $createdTransactions[] = $tx;

                $this->auditLogger->log(
                    $request,
                    $request->user()->id,
                    'InventoryItem',
                    $inv->id,
                    'stock_received_inventory_updated',
                    $beforeInventoryItem,
                    $inv->fresh()->toArray(),
                    'Inventory stock and average purchase price updated by stock receiving.'
                );
            }

            $po->refresh()->load('items');

            $isComplete = $po->items->every(
                fn ($item) => (float) $item->received_quantity >= (float) $item->quantity
            );

            $newStatus = $isComplete ? 'completed' : 'partially_received';
            $before = $po->toArray();
            $fromStatus = $po->status;

            $po->update([
                'status' => $newStatus,
                'received_at' => now(),
            ]);

            PurchaseOrderStatusHistory::create([
                'purchase_order_id' => $po->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'note' => 'Stock received',
                'changed_by' => $request->user()->id,
                'changed_at' => now(),
            ]);

            $this->notificationService->notifyUsersByPermission(
                'inventory.read',
                'Stock received',
                "Purchase order {$po->po_number} has been received into inventory.",
                [
                    'kind' => 'purchase_order_received',
                    'purchase_order_id' => $po->id,
                ]
            );

            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'StockReceiving',
                $recv->id,
                'stock_received',
                null,
                $recv->load('items')->toArray(),
                'Stock receiving recorded with inventory batches and transactions.'
            );

            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'PurchaseOrder',
                $po->id,
                'purchase_order_received',
                $before,
                $po->fresh()->toArray(),
                'Purchase order received.'
            );

            return response()->json([
                'success' => true,
                'message' => 'Stock received and inventory updated successfully',
                'data' => [
                    'receiving' => $recv->load('items.inventoryItem'),
                    'po' => $po->fresh()->load('items', 'receivings.items'),
                    'batches' => collect($createdBatches)->map->fresh()->values(),
                    'transactions' => collect($createdTransactions)->map->fresh()->values(),
                ],
            ]);
        });
    }
}
