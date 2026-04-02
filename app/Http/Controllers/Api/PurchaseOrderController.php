<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private NotificationService $notificationService,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);
        $q = PurchaseOrder::query()->with(['supplier', 'items.inventoryItem'])->orderBy('id', 'desc');
        if ($request->filled('status')) $q->where('status', $request->status);
        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
    }

    public function show($id)
    {
        $po = PurchaseOrder::with(['supplier', 'items.inventoryItem'])->findOrFail($id);
        $this->authorize('view', $po);
        return response()->json(['success' => true, 'data' => $po]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'expected_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id|distinct',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $poNumber = 'PO-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $po = PurchaseOrder::create([
                'po_number' => $poNumber,
                'supplier_id' => $data['supplier_id'],
                'status' => 'draft',
                'total' => 0,
                'created_by' => $request->user()->id,
                'expected_date' => $data['expected_date'] ?? null,
            ]);

            $total = 0;
            foreach ($data['items'] as $it) {
                $inv = InventoryItem::findOrFail($it['inventory_item_id']);
                $qty = (float) $it['quantity'];
                $unit = (float) $it['unit_cost'];
                $line = $qty * $unit;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'inventory_item_id' => $inv->id,
                    'quantity' => $qty,
                    'unit_cost' => $unit,
                    'line_total' => $line,
                ]);
                $total += $line;
            }

            $po->total = $total;
            $po->save();
            $po->load('items.inventoryItem');
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_created', null, $po->toArray(), 'Purchase order created.');

            return response()->json(['success' => true, 'data' => $po], 201);
        });
    }

    public function approve(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $po);
            if ($po->status !== 'draft') {
                return response()->json(['success' => false, 'message' => 'Only draft PO can be approved'], 422);
            }

            $before = $po->toArray();
            $po->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->notificationService->notifyUsersByPermission('stock_receiving.approve', 'Purchase order approved', "Purchase order {$po->po_number} is ready for receiving.", ['kind' => 'purchase_order_approved', 'purchase_order_id' => $po->id]);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_approved', $before, $po->fresh()->toArray(), 'Purchase order approved.');
            return response()->json(['success' => true, 'data' => $po]);
        });
    }

    public function cancel(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('cancel', $po);
            if ($po->status === 'received') {
                return response()->json(['success' => false, 'message' => 'Cannot cancel received PO'], 422);
            }
            if ($po->status === 'cancelled') {
                return response()->json(['success' => false, 'message' => 'PO is already cancelled'], 422);
            }
            $before = $po->toArray();
            $po->update(['status' => 'cancelled']);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_cancelled', $before, $po->fresh()->toArray(), 'Purchase order cancelled.');
            return response()->json(['success' => true, 'data' => $po]);
        });
    }
}
