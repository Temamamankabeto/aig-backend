<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderStatusHistory;
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
        $q = PurchaseOrder::query()
            ->with(['supplier', 'items.inventoryItem', 'receivings'])
            ->orderBy('id', 'desc');

        if ($request->filled('status') && $request->status !== 'all') {
            $q->where('status', $request->status);
        }

        if ($request->filled('supplier_id')) {
            $q->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $q->where(function ($sub) use ($search) {
                $sub->where('po_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
    }


    public function dashboard(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $weekStart = $now->copy()->startOfWeek();
        $validatedStatuses = ['food_validated', 'food_validated'];

        $base = PurchaseOrder::query();

        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value);

        $totalRequests = (clone $base)->count();
        $requestsThisWeek = (clone $base)->where('created_at', '>=', $weekStart)->count();
        $monthlyCost = (float) (clone $base)
            ->where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['cancelled', 'validation_rejected'])
            ->sum('total');

        $recentRequests = PurchaseOrder::query()
            ->with(['supplier', 'items.inventoryItem'])
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'supplier' => $po->supplier?->name ?? 'N/A',
                'items' => $po->items
                    ->map(fn ($item) => $item->inventoryItem?->name)
                    ->filter()
                    ->take(3)
                    ->implode(', '),
                'items_count' => $po->items->count(),
                'amount' => (float) ($po->total ?? 0),
                'status' => $po->status,
                'created_at' => optional($po->created_at)->toDateTimeString(),
            ]);

        $lowStockItems = InventoryItem::query()
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->where('is_active', true)
            ->count();

        $trend = PurchaseOrder::query()
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'), DB::raw('COALESCE(SUM(total), 0) as amount'))
            ->where('created_at', '>=', $now->copy()->subDays(29)->startOfDay())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get()
            ->map(fn ($row) => [
                'day' => $row->day,
                'total' => (int) $row->total,
                'amount' => (float) $row->amount,
            ]);

        $validatedTotal = (int) collect($validatedStatuses)->sum(fn ($status) => (int) ($statusCounts[$status] ?? 0));

        return response()->json([
            'success' => true,
            'message' => 'Purchaser dashboard fetched successfully.',
            'data' => [
                'kpis' => [
                    'total_requests' => $totalRequests,
                    'requests_this_week' => $requestsThisWeek,
                    'pending_validation' => (int) ($statusCounts['submitted'] ?? 0),
                    'validated' => $validatedTotal,
                    'manager_approved' => (int) ($statusCounts['approved'] ?? 0),
                    'partially_received' => (int) ($statusCounts['partially_received'] ?? 0),
                    'completed' => (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['received'] ?? 0)),
                    'rejected' => (int) (($statusCounts['validation_rejected'] ?? 0) + ($statusCounts['cancelled'] ?? 0)),
                    'monthly_cost' => $monthlyCost,
                    'low_stock_items' => $lowStockItems,
                ],
                'status_distribution' => [
                    ['label' => 'Submitted', 'status' => 'submitted', 'value' => (int) ($statusCounts['submitted'] ?? 0)],
                    ['label' => 'Food Controller Validated', 'status' => 'food_validated', 'value' => $validatedTotal],
                    ['label' => 'Approved', 'status' => 'approved', 'value' => (int) ($statusCounts['approved'] ?? 0)],
                    ['label' => 'Partially Received', 'status' => 'partially_received', 'value' => (int) ($statusCounts['partially_received'] ?? 0)],
                    ['label' => 'Completed', 'status' => 'completed', 'value' => (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['received'] ?? 0))],
                    ['label' => 'Rejected/Cancelled', 'status' => 'rejected', 'value' => (int) (($statusCounts['validation_rejected'] ?? 0) + ($statusCounts['cancelled'] ?? 0))],
                ],
                'workflow' => [
                    ['label' => 'Submitted', 'value' => (int) ($statusCounts['submitted'] ?? 0)],
                    ['label' => 'Food Controller Validated', 'value' => $validatedTotal],
                    ['label' => 'Manager Approved', 'value' => (int) ($statusCounts['approved'] ?? 0)],
                    ['label' => 'Received', 'value' => (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['received'] ?? 0) + ($statusCounts['partially_received'] ?? 0))],
                ],
                'recent_requests' => $recentRequests,
                'trend' => $trend,
                'alerts' => [
                    'pending_validation' => (int) ($statusCounts['submitted'] ?? 0),
                    'pending_manager_approval' => $validatedTotal,
                    'approved_awaiting_receiving' => (int) ($statusCounts['approved'] ?? 0),
                    'low_stock_items' => $lowStockItems,
                    'rejected_requests' => (int) (($statusCounts['validation_rejected'] ?? 0) + ($statusCounts['cancelled'] ?? 0)),
                ],
            ],
            'meta' => null,
        ]);
    }

    public function pendingValidation(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $q = PurchaseOrder::query()
            ->with(['supplier', 'items.inventoryItem', 'receivings'])
            ->where('status', 'submitted')
            ->orderBy('submitted_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $q->where(function ($sub) use ($search) {
                $sub->where('po_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Pending purchase validation requests fetched successfully.',
            'data' => $q->paginate((int) ($request->get('per_page', 20))),
            'meta' => null,
        ]);
    }

    public function show($id)
    {
        $po = PurchaseOrder::with([
            'supplier',
            'items.inventoryItem',
            'receivings.items.inventoryItem',
            'histories.changer',
        ])->findOrFail($id);
        $this->authorize('view', $po);
        return response()->json(['success' => true, 'data' => $po]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:3000',
            'status' => 'nullable|in:draft,submitted',
            'reference_source' => 'nullable|string|max:255',
            'from_low_stock_alerts' => 'nullable|boolean',
            'items' => 'nullable|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id|distinct',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.base_unit' => 'nullable|in:kg,L,pcs',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $poNumber = 'PO-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $initialStatus = $data['status'] ?? 'draft';

            $items = $data['items'] ?? [];
            if (($data['from_low_stock_alerts'] ?? false) && empty($items)) {
                $items = InventoryItem::query()
                    ->whereColumn('current_stock', '<=', 'minimum_quantity')
                    ->get()
                    ->map(fn ($item) => [
                        'inventory_item_id' => $item->id,
                        'quantity' => max(round((float) $item->minimum_quantity - (float) $item->current_stock, 3), 0.001),
                        'base_unit' => $item->base_unit,
                        'unit_cost' => (float) ($item->average_purchase_price ?? 0),
                    ])
                    ->all();
            }

            if (empty($items)) {
                return response()->json(['success' => false, 'message' => 'At least one purchase order item is required.'], 422);
            }

            $po = PurchaseOrder::create([
                'po_number' => $poNumber,
                'supplier_id' => $data['supplier_id'],
                'status' => $initialStatus,
                'total' => 0,
                'created_by' => $request->user()->id,
                'submitted_by' => $initialStatus === 'submitted' ? $request->user()->id : null,
                'submitted_at' => $initialStatus === 'submitted' ? now() : null,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'reference_source' => $data['reference_source'] ?? (($data['from_low_stock_alerts'] ?? false) ? 'low_stock_alert' : null),
            ]);

            $total = 0;
            foreach ($items as $it) {
                $inv = InventoryItem::findOrFail($it['inventory_item_id']);
                $qty = round((float) $it['quantity'], 3);
                $unitCost = round((float) $it['unit_cost'], 2);
                $line = round($qty * $unitCost, 2);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'inventory_item_id' => $inv->id,
                    'quantity' => $qty,
                    'base_unit' => $it['base_unit'] ?? $inv->base_unit,
                    'received_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'line_total' => $line,
                ]);
                $total += $line;
            }

            $po->total = $total;
            $po->save();
            $po->load('items.inventoryItem');

            $this->recordStatusHistory($po, null, $po->status, $request->user()->id, 'Purchase order created');
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_created', null, $po->toArray(), 'Purchase order created.');

            return response()->json(['success' => true, 'data' => $po], 201);
        });
    }

    public function submit(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('create', PurchaseOrder::class);

            if (! in_array($po->status, ['draft', 'validation_rejected'], true)) {
                return response()->json(['success' => false, 'message' => 'Only draft or validation-rejected purchase orders can be submitted.'], 422);
            }

            $before = $po->toArray();
            $fromStatus = $po->status;
            $po->update([
                'status' => 'submitted',
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            $this->recordStatusHistory($po, $fromStatus, 'submitted', $request->user()->id, 'Purchase order submitted for F&B validation');
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_submitted', $before, $po->fresh()->toArray(), 'Purchase order submitted.');

            return response()->json(['success' => true, 'data' => $po->fresh()]);
        });
    }

    public function validateOrder(Request $request, $id)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('validate', $po);

            if ($po->status !== 'submitted') {
                return response()->json(['success' => false, 'message' => 'Only submitted purchase requests can be validated by F&B Controller.'], 422);
            }

            $before = $po->toArray();
            $fromStatus = $po->status;
            $note = $data['note'] ?? 'F&B Controller validated recipe and inventory links';

            $po->update(['status' => 'food_validated']);

            $this->recordStatusHistory($po, $fromStatus, 'food_validated', $request->user()->id, $note);
            $this->notificationService->notifyUsersByPermission('purchase_orders.approve', 'Purchase request validated', "Purchase order {$po->po_number} is ready for manager approval.", ['kind' => 'purchase_order_food_validated', 'purchase_order_id' => $po->id]);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_food_validated', $before, $po->fresh()->toArray(), 'Purchase order validated by F&B Controller.');

            return response()->json(['success' => true, 'data' => $po->fresh()->load(['supplier', 'items.inventoryItem', 'receivings'])]);
        });
    }

    public function rejectValidation(Request $request, $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('validate', $po);

            if ($po->status !== 'submitted') {
                return response()->json(['success' => false, 'message' => 'Only submitted purchase requests can be rejected by F&B Controller.'], 422);
            }

            $before = $po->toArray();
            $fromStatus = $po->status;
            $po->update(['status' => 'validation_rejected']);

            $this->recordStatusHistory($po, $fromStatus, 'validation_rejected', $request->user()->id, $data['reason']);
            $this->notificationService->notifyUsersByPermission('purchase_orders.create', 'Purchase request validation rejected', "Purchase order {$po->po_number} needs correction: {$data['reason']}", ['kind' => 'purchase_order_validation_rejected', 'purchase_order_id' => $po->id]);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_validation_rejected', $before, $po->fresh()->toArray(), 'Purchase order validation rejected by F&B Controller.');

            return response()->json(['success' => true, 'data' => $po->fresh()->load(['supplier', 'items.inventoryItem', 'receivings'])]);
        });
    }

    public function approve(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $po);
            if (! in_array($po->status, ['food_validated'], true)) {
                return response()->json(['success' => false, 'message' => 'Only F&B validated purchase requests can be approved.'], 422);
            }

            $before = $po->toArray();
            $fromStatus = $po->status;
            $po->update([
                'status' => 'approved',
                'submitted_by' => $po->submitted_by ?: $request->user()->id,
                'submitted_at' => $po->submitted_at ?: now(),
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $this->recordStatusHistory($po, $fromStatus, 'approved', $request->user()->id, 'Purchase order approved');
            $this->notificationService->notifyUsersByPermission('stock_receiving.approve', 'Purchase order approved', "Purchase order {$po->po_number} is ready for receiving.", ['kind' => 'purchase_order_approved', 'purchase_order_id' => $po->id]);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_approved', $before, $po->fresh()->toArray(), 'Purchase order approved.');
            return response()->json(['success' => true, 'data' => $po->fresh()]);
        });
    }

    public function cancel(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $id, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($id);
            $this->authorize('cancel', $po);
            if (in_array($po->status, ['completed', 'received', 'cancelled'], true)) {
                return response()->json(['success' => false, 'message' => 'This purchase order cannot be cancelled'], 422);
            }

            $before = $po->toArray();
            $fromStatus = $po->status;
            $po->update([
                'status' => 'cancelled',
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancel_reason' => $data['reason'],
            ]);
            $this->recordStatusHistory($po, $fromStatus, 'cancelled', $request->user()->id, $data['reason']);
            $this->auditLogger->log($request, $request->user()->id, 'PurchaseOrder', $po->id, 'purchase_order_cancelled', $before, $po->fresh()->toArray(), 'Purchase order cancelled.');
            return response()->json(['success' => true, 'data' => $po->fresh()]);
        });
    }

    public function history($id)
    {
        $po = PurchaseOrder::findOrFail($id);
        $this->authorize('view', $po);

        return response()->json([
            'success' => true,
            'data' => $po->histories()->with('changer')->orderBy('id')->get(),
        ]);
    }

    private function recordStatusHistory(PurchaseOrder $po, ?string $fromStatus, string $toStatus, ?int $userId, ?string $note = null): void
    {
        PurchaseOrderStatusHistory::create([
            'purchase_order_id' => $po->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'changed_by' => $userId,
            'changed_at' => now(),
        ]);
    }
}
