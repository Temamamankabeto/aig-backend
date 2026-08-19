<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Department;
use App\Models\DepartmentStockConsumption;
use App\Models\InventoryItemBatch;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryTransactionController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private NotificationService $notificationService,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $q = InventoryTransaction::query()->with(['inventoryItem', 'creator:id,name', 'responsibleUser:id,name,email,phone'])->orderBy('id', 'desc');

        if ($request->filled('type')) $q->where('type', $request->type);
        if ($request->filled('reference_type')) {
            $q->where('reference_type', $request->reference_type);
            if ($request->reference_type === 'department_stockout') $q->with('department');
        }
        if ($request->filled('inventory_item_id')) $q->where('inventory_item_id', $request->inventory_item_id);

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 30)))]);
    }

    public function stockBalances(Request $request)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $items = InventoryItem::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';
                $query->where(fn ($itemQuery) => $itemQuery->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 200));

        $items->getCollection()->transform(fn (InventoryItem $item) => [
            'inventory_item_id' => $item->id,
            'item' => $item->name,
            'sku' => $item->sku,
            'store' => 'Main Store',
            'unit' => $item->base_unit,
            'available_quantity' => round((float) $item->current_stock, 3),
            'reserved_quantity' => 0,
            'minimum_quantity' => round((float) $item->minimum_quantity, 3),
            'stock_status' => $item->stock_status,
        ]);

        return response()->json(['success' => true, 'message' => 'Stock balances retrieved.', 'data' => $items]);
    }

    public function returnableIssues(Request $request)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $returned = InventoryTransaction::query()
            ->selectRaw('reference_id, SUM(quantity) as returned_quantity')
            ->where('reference_type', 'department_return')
            ->whereNotNull('reference_id')
            ->groupBy('reference_id');

        $issues = InventoryTransaction::query()
            ->with(['inventoryItem', 'creator:id,name', 'department', 'responsibleUser:id,name,email,phone'])
            ->leftJoinSub($returned, 'returns', fn ($join) => $join->on('returns.reference_id', '=', 'inventory_transactions.id'))
            ->where('inventory_transactions.reference_type', 'department_stockout')
            ->where('inventory_transactions.type', 'out')
            ->where(function ($query) {
                $query->whereNull('inventory_transactions.responsible_user_id')
                    ->orWhere('inventory_transactions.custody_status', 'return_requested');
            })
            ->whereRaw('inventory_transactions.quantity > COALESCE(returns.returned_quantity, 0)')
            ->when($request->filled('inventory_item_id'), fn ($query) => $query->where('inventory_transactions.inventory_item_id', $request->input('inventory_item_id')))
            ->select('inventory_transactions.*')
            ->selectRaw('COALESCE(returns.returned_quantity, 0) as returned_quantity')
            ->selectRaw('CASE WHEN inventory_transactions.responsible_user_id IS NOT NULL THEN inventory_transactions.return_requested_quantity ELSE (inventory_transactions.quantity - COALESCE(returns.returned_quantity, 0)) END as returnable_quantity')
            ->orderByDesc('inventory_transactions.id')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 200));

        return response()->json(['success' => true, 'message' => 'Returnable issues retrieved.', 'data' => $issues]);
    }

    public function returnToStore(Request $request, $issueId)
    {
        $this->authorize('adjust', InventoryTransaction::class);
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $issueId, $data) {
            $issue = InventoryTransaction::query()->lockForUpdate()->findOrFail($issueId);
            if ($issue->type !== 'out' || $issue->reference_type !== 'department_stockout') {
                return response()->json(['success' => false, 'message' => 'Only a department stock issue can be returned.'], 422);
            }

            $alreadyReturned = (float) InventoryTransaction::query()
                ->where('reference_type', 'department_return')
                ->where('reference_id', $issue->id)
                ->lockForUpdate()
                ->get(['quantity'])
                ->sum(fn (InventoryTransaction $return) => (float) $return->quantity);
            $qty = round((float) $data['quantity'], 3);
            $returnable = round((float) $issue->quantity - $alreadyReturned, 3);
            if ($issue->responsible_user_id && $issue->custody_status !== 'return_requested') {
                return response()->json(['success' => false, 'message' => 'The responsible user must request this return first.', 'data' => null, 'meta' => null], 422);
            }
            if ($issue->responsible_user_id && $qty > (float) $issue->return_requested_quantity) {
                return response()->json(['success' => false, 'message' => 'Return quantity exceeds the quantity requested by the responsible user.', 'data' => null, 'meta' => null], 422);
            }
            if ($qty > $returnable) {
                return response()->json(['success' => false, 'message' => "Only {$returnable} remains returnable for this issue."], 422);
            }

            $item = InventoryItem::query()->lockForUpdate()->findOrFail($issue->inventory_item_id);
            $before = $item->toArray();
            $beforeQty = round((float) $item->current_stock, 3);
            $afterQty = round($beforeQty + $qty, 3);
            $item->update(['current_stock' => $afterQty]);

            InventoryItemBatch::create([
                'inventory_item_id' => $item->id,
                'purchase_price' => (float) ($issue->unit_cost ?? $item->average_purchase_price ?? 0),
                'initial_qty' => $qty,
                'remaining_qty' => $qty,
                'expiry_date' => null,
            ]);

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'in',
                'quantity' => $qty,
                'unit_cost' => $issue->unit_cost ?? $item->average_purchase_price,
                'before_quantity' => $beforeQty,
                'after_quantity' => $afterQty,
                'reference_type' => 'department_return',
                'reference_id' => $issue->id,
                'note' => trim($data['reason'] . ' [Return against issue #' . $issue->id . ']'),
                'created_by' => $request->user()->id,
            ]);

            if ($issue->responsible_user_id) {
                $remainingRequest = round((float) $issue->return_requested_quantity - $qty, 3);
                $totalReturned = round($alreadyReturned + $qty, 3);
                $issue->update([
                    'return_requested_quantity' => max(0, $remainingRequest),
                    'custody_status' => $remainingRequest > 0
                        ? 'return_requested'
                        : ($totalReturned + (float) $issue->used_quantity >= (float) $issue->quantity ? 'closed' : 'received'),
                ]);
            }

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_returned_to_store', null, $tx->toArray(), 'Department stock returned to store.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_returned_to_store', $before, $item->fresh()->toArray(), 'Inventory balance increased by department return.');
            $this->notificationService->notifyUser($issue->responsible_user_id, 'Stock return accepted', "The Store Keeper accepted {$qty} {$item->base_unit} of {$item->name}. The main stock balance was updated.", ['kind' => 'department_stock_return_accepted', 'inventory_item_id' => $item->id, 'issue_id' => $issue->id]);

            return response()->json(['success' => true, 'message' => 'Stock returned to store successfully.', 'data' => [
                'item' => $item->fresh(),
                'transaction' => $tx,
                'issue_id' => $issue->id,
                'remaining_returnable_quantity' => round($returnable - $qty, 3),
            ]]);
        });
    }

    public function stockCard(Request $request, $itemId)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $item = InventoryItem::findOrFail($itemId);
        $query = InventoryTransaction::query()->with('creator:id,name')->where('inventory_item_id', $item->id);
        $openingBalance = 0.0;

        if (! empty($data['from'])) {
            $previous = (clone $query)->where('created_at', '<', $data['from'])->orderByDesc('created_at')->orderByDesc('id')->first();
            $openingBalance = (float) ($previous?->after_quantity ?? 0);
            $query->where('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) $query->where('created_at', '<=', $data['to'] . ' 23:59:59');

        $transactions = $query->orderBy('created_at')->orderBy('id')->get();
        if (empty($data['from']) && $transactions->isNotEmpty()) $openingBalance = (float) ($transactions->first()->before_quantity ?? 0);

        $lines = $transactions->map(function (InventoryTransaction $tx) {
            $isReturn = $tx->reference_type === 'department_return';
            $isWaste = $tx->reference_type === 'waste';
            return [
                'id' => $tx->id,
                'date' => $tx->created_at,
                'reference' => $tx->reference_type . ($tx->reference_id ? ' #' . $tx->reference_id : ''),
                'type' => $tx->type,
                'note' => $tx->note,
                'received' => $tx->type === 'in' && ! $isReturn ? (float) $tx->quantity : 0,
                'issued' => $tx->type === 'out' && ! $isWaste ? (float) $tx->quantity : 0,
                'returned' => $isReturn ? (float) $tx->quantity : 0,
                'transferred' => str_starts_with((string) $tx->type, 'transfer_') ? (float) $tx->quantity : 0,
                'adjustment' => $tx->type === 'adjust' ? (float) $tx->quantity : 0,
                'waste' => $isWaste ? (float) $tx->quantity : 0,
                'balance' => (float) $tx->after_quantity,
                'recorded_by' => $tx->creator?->name,
            ];
        });

        return response()->json(['success' => true, 'message' => 'Stock card retrieved.', 'data' => [
            'item' => ['id' => $item->id, 'name' => $item->name, 'sku' => $item->sku, 'unit' => $item->base_unit],
            'store' => 'Main Store',
            'opening_balance' => round($openingBalance, 3),
            'closing_balance' => $lines->isNotEmpty() ? round((float) $lines->last()['balance'], 3) : round($openingBalance, 3),
            'lines' => $lines,
        ]]);
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


    public function stockout(Request $request, $itemId)
    {
        $this->authorize('adjust', InventoryTransaction::class);
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'department_id' => 'required|integer|exists:departments,id',
            'responsible_user_id' => 'required|integer|exists:users,id',
            'reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $itemId, $data) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($itemId);
            $department = Department::query()->where('is_active', true)->find($data['department_id']);
            if (! $department) {
                return response()->json(['success' => false, 'message' => 'The selected department is inactive or unavailable.', 'data' => null, 'meta' => null], 422);
            }
            $responsibleUser = User::query()
                ->whereKey($data['responsible_user_id'])
                ->where('department_id', $department->id)
                ->where('is_active', true)
                ->first();
            if (! $responsibleUser) {
                return response()->json(['success' => false, 'message' => 'Select an active responsible user assigned to the selected department.', 'data' => null, 'meta' => null], 422);
            }
            $before = $item->toArray();
            $beforeQty = round((float) $item->current_stock, 3);
            $qty = round((float) $data['quantity'], 3);

            if ($beforeQty < $qty) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock for department stockout'], 422);
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

            $note = trim('Department: ' . $department->name . ' - ' . $data['reason'] . ' [' . $qty . ' ' . $item->base_unit . ']');

            $tx = InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'type' => 'out',
                'quantity' => $qty,
                'unit_cost' => $item->average_purchase_price,
                'before_quantity' => $beforeQty,
                'after_quantity' => (float) $item->current_stock,
                'reference_type' => 'department_stockout',
                'reference_id' => $department->id,
                'note' => $note,
                'created_by' => $request->user()->id,
                'responsible_user_id' => $responsibleUser->id,
                'custody_status' => 'issued',
                'used_quantity' => 0,
                'return_requested_quantity' => 0,
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $tx->id, 'inventory_department_stockout', null, $tx->toArray(), 'Inventory item issued to department.');
            $this->auditLogger->log($request, $request->user()->id, 'InventoryItem', $item->id, 'inventory_item_department_stockout', $before, $item->fresh()->toArray(), 'Inventory item quantity reduced for department stockout.');
            $this->notificationService->notifyUser($responsibleUser->id, 'Department stock assigned', "{$qty} {$item->base_unit} of {$item->name} was sent to you. Please receive it from Stock & Consumption.", ['kind' => 'department_stock_assigned', 'inventory_item_id' => $item->id, 'issue_id' => $tx->id]);

            return response()->json(['success' => true, 'message' => 'Department stockout assigned to the responsible user.', 'data' => ['item' => $item->fresh(), 'tx' => $tx->load(['department', 'responsibleUser:id,name,email,phone'])], 'meta' => null]);
        });
    }

    public function departmentUsers(Request $request, Department $department)
    {
        $this->authorize('viewAny', InventoryTransaction::class);
        $users = User::query()
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->select('id', 'name', 'email', 'phone', 'department_id')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'message' => 'Department users retrieved.', 'data' => $users, 'meta' => null]);
    }

    public function myDepartmentIssues(Request $request)
    {
        $returned = InventoryTransaction::query()
            ->selectRaw('reference_id, SUM(quantity) as returned_quantity')
            ->where('reference_type', 'department_return')
            ->whereNotNull('reference_id')
            ->groupBy('reference_id');
        $issues = InventoryTransaction::query()
            ->with(['inventoryItem', 'department', 'creator:id,name'])
            ->leftJoinSub($returned, 'returns', fn ($join) => $join->on('returns.reference_id', '=', 'inventory_transactions.id'))
            ->where('reference_type', 'department_stockout')
            ->where('responsible_user_id', $request->user()->id)
            ->where('custody_status', '<>', 'closed')
            ->select('inventory_transactions.*')
            ->selectRaw('COALESCE(returns.returned_quantity, 0) as returned_quantity')
            ->selectRaw('(inventory_transactions.quantity - inventory_transactions.used_quantity - COALESCE(returns.returned_quantity, 0) - inventory_transactions.return_requested_quantity) as available_quantity')
            ->orderByDesc('inventory_transactions.id')
            ->paginate(min(max((int) $request->input('per_page', 30), 1), 100));

        return response()->json(['success' => true, 'message' => 'Assigned department stock retrieved.', 'data' => $issues, 'meta' => null]);
    }

    public function acknowledgeDepartmentIssue(Request $request, InventoryTransaction $issue)
    {
        $this->assertResponsibleUser($request, $issue);
        if ($issue->custody_status !== 'issued') {
            return response()->json(['success' => false, 'message' => 'Only newly issued stock can be acknowledged.', 'data' => null, 'meta' => null], 422);
        }

        $before = $issue->toArray();
        $issue->update(['custody_status' => 'received', 'received_at' => now()]);
        $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $issue->id, 'department_stock_received', $before, $issue->fresh()->toArray(), 'Responsible user acknowledged department stock.');

        return response()->json(['success' => true, 'message' => 'Stock receipt acknowledged.', 'data' => $issue->fresh()->load(['inventoryItem', 'department']), 'meta' => null]);
    }

    public function recordDepartmentUse(Request $request, InventoryTransaction $issue)
    {
        $this->assertResponsibleUser($request, $issue);
        $data = $request->validate(['quantity' => 'required|numeric|min:0.001', 'note' => 'nullable|string|max:500']);

        return DB::transaction(function () use ($request, $issue, $data) {
            $issue = InventoryTransaction::query()->lockForUpdate()->findOrFail($issue->id);
            if (! in_array($issue->custody_status, ['received', 'partially_used'], true)) {
                return response()->json(['success' => false, 'message' => 'Acknowledge receipt before recording usage.', 'data' => null, 'meta' => null], 422);
            }
            $returned = $this->returnedQuantity($issue->id);
            $available = round((float) $issue->quantity - (float) $issue->used_quantity - $returned - (float) $issue->return_requested_quantity, 3);
            $qty = round((float) $data['quantity'], 3);
            if ($qty > $available) {
                return response()->json(['success' => false, 'message' => "Only {$available} remains available to use.", 'data' => null, 'meta' => null], 422);
            }
            $before = $issue->toArray();
            $used = round((float) $issue->used_quantity + $qty, 3);
            $issue->update(['used_quantity' => $used, 'custody_status' => $used + $returned >= (float) $issue->quantity ? 'closed' : 'partially_used']);
            DepartmentStockConsumption::create([
                'inventory_transaction_id' => $issue->id,
                'inventory_item_id' => $issue->inventory_item_id,
                'department_id' => $issue->reference_id,
                'recorded_by' => $request->user()->id,
                'quantity' => $qty,
                'unit_cost' => round((float) ($issue->unit_cost ?? 0), 3),
                'total_cost' => round($qty * (float) ($issue->unit_cost ?? 0), 2),
                'note' => $data['note'] ?? null,
                'approval_status' => 'pending',
                'consumed_at' => now(),
            ]);
            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $issue->id, 'department_stock_used', $before, $issue->fresh()->toArray(), $data['note'] ?? 'Department stock usage recorded.');
            return response()->json(['success' => true, 'message' => 'Usage recorded.', 'data' => $issue->fresh()->load(['inventoryItem', 'department']), 'meta' => null]);
        });
    }

    public function requestDepartmentReturn(Request $request, InventoryTransaction $issue)
    {
        $this->assertResponsibleUser($request, $issue);
        $data = $request->validate(['quantity' => 'required|numeric|min:0.001', 'reason' => 'required|string|max:500']);

        return DB::transaction(function () use ($request, $issue, $data) {
            $issue = InventoryTransaction::query()->lockForUpdate()->findOrFail($issue->id);
            if (! in_array($issue->custody_status, ['received', 'partially_used'], true)) {
                return response()->json(['success' => false, 'message' => 'Only received stock can be requested for return.', 'data' => null, 'meta' => null], 422);
            }
            $returned = $this->returnedQuantity($issue->id);
            $available = round((float) $issue->quantity - (float) $issue->used_quantity - $returned, 3);
            $qty = round((float) $data['quantity'], 3);
            if ($qty > $available) {
                return response()->json(['success' => false, 'message' => "Only {$available} remains available for return.", 'data' => null, 'meta' => null], 422);
            }
            $before = $issue->toArray();
            $issue->update(['return_requested_quantity' => $qty, 'return_request_reason' => $data['reason'], 'return_requested_at' => now(), 'custody_status' => 'return_requested']);
            $this->auditLogger->log($request, $request->user()->id, 'InventoryTransaction', $issue->id, 'department_stock_return_requested', $before, $issue->fresh()->toArray(), 'Responsible user requested return to store.');
            $this->notificationService->notifyUsersByPermission('inventory.adjustments.create', 'Department stock return requested', "{$request->user()->name} requested return of {$qty} units for issue #{$issue->id}.", ['kind' => 'department_stock_return_requested', 'inventory_item_id' => $issue->inventory_item_id, 'issue_id' => $issue->id]);
            return response()->json(['success' => true, 'message' => 'Return request sent to the Store Keeper.', 'data' => $issue->fresh()->load(['inventoryItem', 'department']), 'meta' => null]);
        });
    }

    private function assertResponsibleUser(Request $request, InventoryTransaction $issue): void
    {
        abort_unless($issue->reference_type === 'department_stockout' && (int) $issue->responsible_user_id === (int) $request->user()->id, 403, 'This stock issue is not assigned to you.');
    }

    private function returnedQuantity(int $issueId): float
    {
        return round((float) InventoryTransaction::query()->where('reference_type', 'department_return')->where('reference_id', $issueId)->sum('quantity'), 3);
    }

    public function departmentConsumptionReport(Request $request)
    {
        abort_unless($request->user()->department_id, 422, 'Your user account must be assigned to a department.');
        return $this->consumptionReport($request, (int) $request->user()->department_id);
    }

    public function foodControllerConsumptionReport(Request $request)
    {
        return $this->consumptionReport($request, null);
    }

    private function consumptionReport(Request $request, ?int $departmentId)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'approval_status' => ['nullable', 'in:pending,approved,all'],
        ]);
        $query = DepartmentStockConsumption::query()->with(['inventoryItem:id,name,sku,base_unit', 'department:id,name,code', 'recorder:id,name', 'approver:id,name', 'issue:id'])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when(! $departmentId && ! empty($data['department_id']), fn ($q) => $q->where('department_id', $data['department_id']))
            ->when(! empty($data['inventory_item_id']), fn ($q) => $q->where('inventory_item_id', $data['inventory_item_id']))
            ->when(! empty($data['date_from']), fn ($q) => $q->whereDate('consumed_at', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('consumed_at', '<=', $data['date_to']))
            ->when($request->filled('approval_status') && $request->input('approval_status') !== 'all', fn ($q) => $q->where('approval_status', $request->input('approval_status')))
            ->latest('consumed_at');
        $total = ! empty($data['inventory_item_id']) ? (clone $query)->sum('quantity') : null;
        $rows = $query->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        return response()->json(['success' => true, 'message' => 'Department consumption report retrieved.', 'data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'total_quantity' => $total === null ? null : round((float) $total, 3)]]);
    }

    public function approveConsumptions(Request $request)
    {
        $data = $request->validate([
            'selection_mode' => ['required', 'in:selected,filtered'],
            'consumption_ids' => ['required_if:selection_mode,selected', 'array', 'min:1'],
            'consumption_ids.*' => ['integer', 'distinct', 'exists:department_stock_consumptions,id'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
        ]);
        return DB::transaction(function () use ($request, $data) {
            $query = DepartmentStockConsumption::query()->where('approval_status', 'pending');
            if ($data['selection_mode'] === 'selected') $query->whereIn('id', $data['consumption_ids']);
            else $query->when(! empty($data['date_from']), fn ($q) => $q->whereDate('consumed_at', '>=', $data['date_from']))
                ->when(! empty($data['date_to']), fn ($q) => $q->whereDate('consumed_at', '<=', $data['date_to']))
                ->when(! empty($data['inventory_item_id']), fn ($q) => $q->where('inventory_item_id', $data['inventory_item_id']));
            $rows = $query->lockForUpdate()->get();
            if ($rows->isEmpty()) return response()->json(['success' => false, 'message' => 'No pending consumption rows match the selection.', 'data' => null, 'meta' => null], 422);
            if ($data['selection_mode'] === 'selected' && $rows->count() !== count($data['consumption_ids'])) {
                return response()->json(['success' => false, 'message' => 'One or more selected consumption rows are no longer pending. Refresh and select again.', 'data' => null, 'meta' => null], 422);
            }
            $batch = (string) Str::uuid(); $now = now();
            DepartmentStockConsumption::whereIn('id', $rows->pluck('id'))->update(['approval_status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => $now, 'approval_batch' => $batch]);
            $total = round((float) $rows->sum('total_cost'), 2);
            $this->auditLogger->log($request, $request->user()->id, 'DepartmentStockConsumption', (int) $rows->first()->id, 'consumption_cost_approved', null, ['approval_batch' => $batch, 'ids' => $rows->pluck('id')->all(), 'total_cost' => $total], 'F&B Controller approved consumption cost batch.');
            $this->notificationService->notifyUsersByPermission('payments.read', 'Consumption cost approved', "Approved consumption cost batch totals {$total} ETB.", ['kind' => 'consumption_cost_approved', 'approval_batch' => $batch]);
            return response()->json(['success' => true, 'message' => 'Consumption cost approved.', 'data' => ['approval_batch' => $batch, 'approved_count' => $rows->count(), 'total_cost' => $total], 'meta' => null]);
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
