<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DepartmentStockoutRequest;
use App\Models\InventoryItem;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentStockoutRequestController extends Controller
{
    public function __construct(private AuditLogger $auditLogger, private NotificationService $notificationService) {}

    private function baseQuery()
    {
        return DepartmentStockoutRequest::query()->with(['department', 'inventoryItem', 'requester:id,name,email,department_id', 'validator:id,name', 'issuer:id,name', 'inventoryTransaction']);
    }

    public function items(Request $request)
    {
        abort_unless($request->user()->department_id, 422, 'Your user account must be assigned to a department.');
        $items = InventoryItem::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'base_unit', 'current_stock']);
        return response()->json(['success' => true, 'message' => 'Requestable inventory items retrieved.', 'data' => $items, 'meta' => null]);
    }

    public function mine(Request $request)
    {
        $rows = $this->baseQuery()->where('requested_by', $request->user()->id)
            ->where(function ($query) {
                $query->where('status', '<>', 'issued')
                    ->orWhereHas('inventoryTransaction', fn ($issue) => $issue->where('custody_status', 'issued'));
            })->latest('id')->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        return $this->page($rows, 'Department stock-out requests retrieved.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'], 'quantity' => ['required', 'numeric', 'min:0.001'], 'reason' => ['required', 'string', 'max:500']]);
        abort_unless($request->user()->department_id, 422, 'Your user account must be assigned to a department.');
        $item = InventoryItem::query()->where('is_active', true)->findOrFail($data['inventory_item_id']);
        $row = DB::transaction(function () use ($request, $data, $item) {
            $row = DepartmentStockoutRequest::create(['request_number' => 'DSR-TMP-'.Str::uuid(), 'department_id' => $request->user()->department_id, 'inventory_item_id' => $item->id, 'quantity' => round((float) $data['quantity'], 3), 'reason' => trim($data['reason']), 'status' => 'submitted', 'requested_by' => $request->user()->id, 'requested_at' => now()]);
            $row->update(['request_number' => 'DSR-'.now()->format('Ymd').'-'.str_pad((string) $row->id, 5, '0', STR_PAD_LEFT)]);
            $this->auditLogger->log($request, $request->user()->id, 'DepartmentStockoutRequest', $row->id, 'department_stockout_requested', null, $row->toArray(), 'Department requested stock-out.');
            $this->notificationService->notifyUsersByPermission('inventory.read', 'Department stock-out request', "{$request->user()->name} submitted {$row->quantity} {$item->base_unit} of {$item->name} for validation.", ['kind' => 'department_stockout_requested', 'request_id' => $row->id]);
            return $row;
        });
        return response()->json(['success' => true, 'message' => 'Stock-out request submitted for F&B Controller validation.', 'data' => $row->load(['department', 'inventoryItem', 'requester']), 'meta' => null], 201);
    }

    public function validationQueue(Request $request)
    {
        $rows = $this->filtered($request)->whereIn('status', ['submitted', 'validated', 'rejected', 'issued'])->latest('id')->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        return $this->page($rows, 'Stock-out validation queue retrieved.');
    }

    public function validateRequest(Request $request, DepartmentStockoutRequest $stockoutRequest)
    {
        $data = $request->validate(['validation_note' => ['nullable', 'string', 'max:1000']]);
        return DB::transaction(function () use ($request, $stockoutRequest, $data) {
            $row = DepartmentStockoutRequest::lockForUpdate()->findOrFail($stockoutRequest->id);
            if ($row->status !== 'submitted') return response()->json(['success' => false, 'message' => 'Only submitted requests can be validated.', 'data' => null, 'meta' => null], 422);
            $before = $row->toArray();
            $row->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now(), 'validation_note' => $data['validation_note'] ?? null]);
            $this->auditLogger->log($request, $request->user()->id, 'DepartmentStockoutRequest', $row->id, 'department_stockout_validated', $before, $row->fresh()->toArray(), 'F&B Controller validated stock-out request.');
            $this->notificationService->notifyUsersByPermission('inventory.adjustments.create', 'Validated stock-out request', "Request {$row->request_number} is ready for Store Keeper issue.", ['kind' => 'department_stockout_validated', 'request_id' => $row->id]);
            return response()->json(['success' => true, 'message' => 'Stock-out request validated.', 'data' => $row->fresh()->load(['department', 'inventoryItem', 'requester', 'validator']), 'meta' => null]);
        });
    }

    public function reject(Request $request, DepartmentStockoutRequest $stockoutRequest)
    {
        $data = $request->validate(['validation_note' => ['required', 'string', 'max:1000']]);
        return DB::transaction(function () use ($request, $stockoutRequest, $data) {
            $row = DepartmentStockoutRequest::lockForUpdate()->findOrFail($stockoutRequest->id);
            if ($row->status !== 'submitted') return response()->json(['success' => false, 'message' => 'Only submitted requests can be rejected.', 'data' => null, 'meta' => null], 422);
            $before = $row->toArray();
            $row->update(['status' => 'rejected', 'validated_by' => $request->user()->id, 'validated_at' => now(), 'validation_note' => trim($data['validation_note'])]);
            $this->auditLogger->log($request, $request->user()->id, 'DepartmentStockoutRequest', $row->id, 'department_stockout_rejected', $before, $row->fresh()->toArray(), 'F&B Controller rejected stock-out request.');
            $this->notificationService->notifyUser($row->requested_by, 'Stock-out request rejected', "Request {$row->request_number} was rejected: {$row->validation_note}", ['kind' => 'department_stockout_rejected', 'request_id' => $row->id]);
            return response()->json(['success' => true, 'message' => 'Stock-out request rejected.', 'data' => $row->fresh()->load(['department', 'inventoryItem', 'requester', 'validator']), 'meta' => null]);
        });
    }

    public function issueQueue(Request $request)
    {
        $rows = $this->filtered($request)->whereIn('status', ['validated', 'issued'])->latest('id')->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        return $this->page($rows, 'Validated stock-out requests retrieved.');
    }

    public function issue(Request $request, DepartmentStockoutRequest $stockoutRequest)
    {
        return DB::transaction(function () use ($request, $stockoutRequest) {
            $row = DepartmentStockoutRequest::lockForUpdate()->findOrFail($stockoutRequest->id);
            if ($row->status !== 'validated') return response()->json(['success' => false, 'message' => 'Only validated requests can be issued.', 'data' => null, 'meta' => null], 422);
            $item = InventoryItem::lockForUpdate()->findOrFail($row->inventory_item_id);
            $qty = round((float) $row->quantity, 3); $beforeQty = round((float) $item->current_stock, 3);
            if ($beforeQty < $qty) return response()->json(['success' => false, 'message' => "Insufficient stock. Available: {$beforeQty} {$item->base_unit}.", 'data' => null, 'meta' => null], 422);
            $item->update(['current_stock' => round($beforeQty - $qty, 3)]);
            $remaining = $qty;
            foreach (InventoryItemBatch::where('inventory_item_id', $item->id)->where('remaining_qty', '>', 0)->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')->lockForUpdate()->get() as $batch) {
                if ($remaining <= 0) break; $consume = min((float) $batch->remaining_qty, $remaining); $batch->update(['remaining_qty' => round((float) $batch->remaining_qty - $consume, 3)]); $remaining = round($remaining - $consume, 3);
            }
            $tx = InventoryTransaction::create(['inventory_item_id' => $item->id, 'type' => 'out', 'quantity' => $qty, 'unit_cost' => $item->average_purchase_price, 'before_quantity' => $beforeQty, 'after_quantity' => $item->current_stock, 'reference_type' => 'department_stockout', 'reference_id' => $row->department_id, 'note' => "Request {$row->request_number}: {$row->reason} [{$qty} {$item->base_unit}]", 'created_by' => $request->user()->id, 'responsible_user_id' => $row->requested_by, 'custody_status' => 'issued', 'used_quantity' => 0, 'return_requested_quantity' => 0]);
            $before = $row->toArray(); $row->update(['status' => 'issued', 'issued_by' => $request->user()->id, 'issued_at' => now(), 'inventory_transaction_id' => $tx->id]);
            $this->auditLogger->log($request, $request->user()->id, 'DepartmentStockoutRequest', $row->id, 'department_stockout_issued', $before, $row->fresh()->toArray(), 'Store Keeper accepted and issued validated request.');
            $this->notificationService->notifyUser($row->requested_by, 'Requested stock issued', "{$qty} {$item->base_unit} of {$item->name} was issued. Please acknowledge receipt.", ['kind' => 'department_stockout_issued', 'request_id' => $row->id, 'issue_id' => $tx->id]);
            return response()->json(['success' => true, 'message' => 'Validated request accepted and stock issued.', 'data' => $row->fresh()->load(['department', 'inventoryItem', 'requester', 'validator', 'issuer', 'inventoryTransaction']), 'meta' => null]);
        });
    }

    private function filtered(Request $request)
    {
        return $this->baseQuery()->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))->when($request->filled('search'), function ($q) use ($request) { $term = '%'.trim((string) $request->input('search')).'%'; $q->where(fn ($n) => $n->where('request_number', 'like', $term)->orWhereHas('inventoryItem', fn ($i) => $i->where('name', 'like', $term))->orWhereHas('department', fn ($d) => $d->where('name', 'like', $term))); });
    }

    private function page($rows, string $message)
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }
}
