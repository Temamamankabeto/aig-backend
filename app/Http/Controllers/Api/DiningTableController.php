<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\AssignWaiterRequest;
use App\Http\Requests\Authz\SetTableStatusRequest;
use App\Http\Requests\Authz\StoreDiningTableRequest;
use App\Http\Requests\Authz\TransferTableRequest;
use App\Http\Requests\Authz\UpdateDiningTableRequest;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Support\TableStatusHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiningTableController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
        private NotificationService $notificationService
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', DiningTable::class);

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));

        $q = DiningTable::query()
            ->with([
                'waiters:id,name',
                'orders' => fn ($query) => $query
                    ->with('bill:id,order_id,status,paid_amount,balance')
                    ->latest('ordered_at')
                    ->latest('id'),
            ]);

        if ($request->filled('search')) {
            $s = trim((string) $request->get('search'));
            $q->where(function ($sub) use ($s) {
                $sub->where('table_number', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%")
                    ->orWhere('section', 'like', "%{$s}%");
            });
        }

        if ($request->filled('section')) {
            $q->where('section', $request->get('section'));
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', (bool) $request->boolean('is_active'));
        }

        if ($request->filled('is_public')) {
            $q->where('is_public', (bool) $request->boolean('is_public'));
        }

        if ($request->filled('waiter_id')) {
            $waiterId = (int) $request->get('waiter_id');
            $q->where(function ($sub) use ($waiterId) {
                $sub->where('assigned_waiter_id', $waiterId)
                    ->orWhereHas('waiters', fn ($w) => $w->where('users.id', $waiterId));
            });
        }

        $tables = $q
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('table_number')
            ->paginate($perPage)
            ->through(fn (DiningTable $table) => $this->transformTable($table));

        if ($request->filled('status')) {
            $status = (string) $request->get('status');
            $tables->setCollection(
                $tables->getCollection()->filter(fn ($row) => ($row['operational_status'] ?? null) === $status)->values()
            );
        }

        return response()->json([
            'success' => true,
            'data' => $tables,
            'summary' => $this->summary(),
        ]);
    }

    public function show($id)
    {
        $table = DiningTable::with([
            'waiters:id,name',
            'orders' => fn ($query) => $query
                ->with(['waiter:id,name', 'bill:id,order_id,status,paid_amount,balance'])
                ->latest('ordered_at')
                ->latest('id'),
        ])->findOrFail($id);
        $this->authorize('view', $table);

        return response()->json([
            'success' => true,
            'data' => $this->transformTable($table, true),
        ]);
    }

    public function store(StoreDiningTableRequest $request)
    {
        $this->authorize('create', DiningTable::class);
        $data = $request->validated();

        $table = DB::transaction(function () use ($request, $data) {
            $table = DiningTable::create([
                'table_number' => $data['table_number'],
                'name' => $data['name'] ?? ('Table ' . $data['table_number']),
                'capacity' => $data['capacity'] ?? 4,
                'section' => $data['section'] ?? null,
                'status' => 'available',
                'assigned_waiter_id' => $data['waiter_ids'][0] ?? null,
                'is_active' => true,
                'is_public' => (bool) ($data['is_public'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            if (! empty($data['waiter_ids'])) {
                $table->waiters()->sync($data['waiter_ids']);
                $this->notificationService->notifyUserIds(
                    $data['waiter_ids'],
                    'Table assigned',
                    "You have been assigned to {$table->name}.",
                    ['kind' => 'table_assignment', 'table_id' => $table->id]
                );
            }

            $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_created', null, $table->load('waiters:id,name')->toArray(), 'Dining table created.');

            return $table->fresh()->load('waiters:id,name');
        });

        return response()->json(['success' => true, 'data' => $this->transformTable($table)], 201);
    }

    public function update(UpdateDiningTableRequest $request, $id)
    {
        $table = DiningTable::with('waiters:id,name')->findOrFail($id);
        $this->authorize('update', $table);
        $before = $table->toArray();
        $data = $request->validated();

        DB::transaction(function () use ($request, $table, $data, $before) {
            $table->update([
                'table_number' => $data['table_number'],
                'name' => $data['name'] ?? ($table->name ?: ('Table ' . $data['table_number'])),
                'capacity' => $data['capacity'],
                'section' => $data['section'] ?? null,
                'is_public' => array_key_exists('is_public', $data) ? (bool) $data['is_public'] : $table->is_public,
                'sort_order' => (int) ($data['sort_order'] ?? $table->sort_order),
                'assigned_waiter_id' => array_key_exists('waiter_ids', $data) ? ($data['waiter_ids'][0] ?? null) : $table->assigned_waiter_id,
            ]);

            if (array_key_exists('waiter_ids', $data)) {
                $table->waiters()->sync($data['waiter_ids'] ?? []);
            }

            $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_updated', $before, $table->fresh()->load('waiters:id,name')->toArray(), 'Dining table updated.');
        });

        return response()->json(['success' => true, 'data' => $this->transformTable($table->fresh()->load('waiters:id,name'))]);
    }

    public function assignWaiter(AssignWaiterRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('assignWaiter', $table);

        $before = $table->load('waiters:id,name')->toArray();
        $waiterIds = $request->validated()['waiter_ids'];
        $table->waiters()->syncWithoutDetaching($waiterIds);
        $table->assigned_waiter_id = $table->waiters()->value('users.id') ?? $waiterIds[0] ?? $table->assigned_waiter_id;
        $table->save();
        $after = $table->fresh()->load('waiters:id,name')->toArray();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_waiters_assigned', $before, $after, 'Waiters assigned to table.');

        $this->notificationService->notifyUserIds(
            $waiterIds,
            'Table assigned',
            "You have been assigned to {$table->name}.",
            ['kind' => 'table_assignment', 'table_id' => $table->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Waiters assigned',
            'data' => $this->transformTable($table->fresh()->load('waiters:id,name')),
        ]);
    }

    public function unassignWaiter(Request $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('assignWaiter', $table);

        $data = $request->validate([
            'waiter_ids' => ['nullable', 'array'],
            'waiter_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $before = $table->load('waiters:id,name')->toArray();

        if (empty($data['waiter_ids'])) {
            $table->waiters()->detach();
            $table->assigned_waiter_id = null;
            $table->save();
        } else {
            $table->waiters()->detach($data['waiter_ids']);
            if (in_array((int) $table->assigned_waiter_id, $data['waiter_ids'], true)) {
                $table->assigned_waiter_id = $table->waiters()->value('users.id');
                $table->save();
            }
        }

        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_waiters_unassigned', $before, $table->fresh()->load('waiters:id,name')->toArray(), 'Waiters unassigned from table.');

        return response()->json([
            'success' => true,
            'message' => 'Waiter assignment updated',
            'data' => $this->transformTable($table->fresh()->load('waiters:id,name')),
        ]);
    }

    public function transfer(TransferTableRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('transfer', $table);

        $before = $table->load('waiters:id,name')->toArray();
        $toWaiterIds = $request->validated()['to_waiter_ids'];
        $table->waiters()->sync($toWaiterIds);
        $table->assigned_waiter_id = $toWaiterIds[0] ?? null;
        $table->save();
        $after = $table->fresh()->load('waiters:id,name')->toArray();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_transferred', $before, $after, 'Table waiter assignment transferred.');

        $this->notificationService->notifyUserIds(
            $toWaiterIds,
            'Table transferred',
            "{$table->name} has been transferred to you.",
            ['kind' => 'table_transfer', 'table_id' => $table->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Table transferred',
            'data' => $this->transformTable($table->fresh()->load('waiters:id,name')),
        ]);
    }

    public function transferOrders(Request $request, $id)
    {
        $fromTable = DiningTable::findOrFail($id);
        $this->authorize('transfer', $fromTable);

        $data = $request->validate([
            'to_table_id' => ['required', 'integer', 'different:id', 'exists:dining_tables,id'],
            'move_waiters' => ['nullable', 'boolean'],
        ]);

        $toTable = DiningTable::findOrFail($data['to_table_id']);

        if (! $toTable->is_active) {
            return response()->json(['success' => false, 'message' => 'Target table is inactive.'], 422);
        }

        DB::transaction(function () use ($request, $fromTable, $toTable, $data) {
            $activeOrders = Order::where('table_id', $fromTable->id)
                ->whereNotIn('status', ['completed', 'cancelled', 'void'])
                ->get();

            foreach ($activeOrders as $order) {
                $order->update(['table_id' => $toTable->id]);
            }

            if ($request->boolean('move_waiters', true)) {
                $waiterIds = $fromTable->waiters()->pluck('users.id')->all();
                if (! empty($waiterIds)) {
                    $toTable->waiters()->syncWithoutDetaching($waiterIds);
                    $toTable->assigned_waiter_id = $waiterIds[0] ?? $toTable->assigned_waiter_id;
                    $toTable->save();
                }
            }

            TableStatusHelper::syncPhysicalStatus($fromTable->fresh());
            TableStatusHelper::syncPhysicalStatus($toTable->fresh());

            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'DiningTable',
                $fromTable->id,
                'table_orders_transferred',
                ['from_table_id' => $fromTable->id, 'to_table_id' => $toTable->id],
                ['moved_order_count' => $activeOrders->count()],
                'Active orders transferred between tables.'
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Active orders transferred successfully.',
            'data' => [
                'from_table' => $this->transformTable($fromTable->fresh()->load(['waiters:id,name', 'orders.bill'])),
                'to_table' => $this->transformTable($toTable->fresh()->load(['waiters:id,name', 'orders.bill'])),
            ],
        ]);
    }

    public function setStatus(SetTableStatusRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('setStatus', $table);

        if (! $table->is_active) {
            return response()->json(['success' => false, 'message' => 'Table is inactive'], 400);
        }

        $hasActiveOrders = $table->orders()
            ->whereNotIn('status', ['completed', 'cancelled', 'void'])
            ->exists();

        if ($hasActiveOrders && $request->validated()['status'] === 'available') {
            return response()->json(['success' => false, 'message' => 'Table has active orders and cannot be marked available.'], 422);
        }

        $before = $table->toArray();
        $table->status = $request->validated()['status'];
        $table->save();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_status_changed', $before, $table->fresh()->toArray(), 'Dining table status changed.');

        return response()->json(['success' => true, 'data' => $this->transformTable($table->fresh()->load('waiters:id,name'))]);
    }

    public function toggleActive(Request $request, $id)
    {
        $table = DiningTable::with('waiters:id,name')->findOrFail($id);
        $this->authorize('toggleActive', $table);

        $activeOrders = $table->orders()
            ->whereNotIn('status', ['completed', 'cancelled', 'void'])
            ->count();

        if ($table->is_active && $activeOrders > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot deactivate a table with active orders.'], 422);
        }

        $before = $table->toArray();
        $table->is_active = ! $table->is_active;

        if (! $table->is_active) {
            $table->status = 'available';
        }

        $table->save();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_toggled', $before, $table->fresh()->toArray(), 'Dining table active flag toggled.');

        return response()->json(['success' => true, 'data' => $this->transformTable($table->fresh()->load('waiters:id,name'))]);
    }

    public function destroy(Request $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('delete', $table);

        $activeOrders = $table->orders()
            ->whereNotIn('status', ['completed', 'cancelled', 'void'])
            ->count();

        if ($activeOrders > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a table with active orders. Deactivate or release it first.',
                'data' => null,
            ], 422);
        }

        $before = $table->toArray();
        $table->waiters()->detach();
        $table->delete();

        $this->auditLogger->log(
            $request,
            $request->user()->id,
            'DiningTable',
            $id,
            'table_deleted',
            $before,
            null,
            'Dining table deleted.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Table deleted successfully.',
            'data' => null,
        ]);
    }

    public function summary()
    {
        $tables = DiningTable::with(['orders' => fn ($query) => $query->with('bill:id,order_id,status,paid_amount,balance')])->get();

        $statuses = $tables
            ->map(fn (DiningTable $table) => TableStatusHelper::operationalStatus($table))
            ->countBy();

        return [
            'total_tables' => $tables->count(),
            'active_tables' => $tables->where('is_active', true)->count(),
            'inactive_tables' => $tables->where('is_active', false)->count(),
            'public_tables' => $tables->where('is_public', true)->count(),
            'status_counts' => [
                'available' => (int) ($statuses['available'] ?? 0),
                'reserved' => (int) ($statuses['reserved'] ?? 0),
                'occupied' => (int) ($statuses['occupied'] ?? 0),
                'pending_order' => (int) ($statuses['pending_order'] ?? 0),
                'order_in_progress' => (int) ($statuses['order_in_progress'] ?? 0),
                'awaiting_bill' => (int) ($statuses['awaiting_bill'] ?? 0),
                'awaiting_payment' => (int) ($statuses['awaiting_payment'] ?? 0),
                'cleaning' => (int) ($statuses['cleaning'] ?? 0),
                'inactive' => (int) ($statuses['inactive'] ?? 0),
            ],
            'section_counts' => $tables->groupBy(fn ($table) => $table->section ?: 'Unassigned')->map->count()->sortKeys()->all(),
        ];
    }

    public function sections()
    {
        $this->authorize('viewAny', DiningTable::class);

        $sections = DiningTable::query()
            ->select('section')
            ->whereNotNull('section')
            ->distinct()
            ->orderBy('section')
            ->pluck('section');

        return response()->json([
            'success' => true,
            'data' => $sections,
        ]);
    }

    public function history(Request $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('view', $table);

        $orders = $table->orders()
            ->with(['waiter:id,name', 'bill:id,order_id,bill_number,status,total,paid_amount,balance'])
            ->latest('ordered_at')
            ->paginate(max(1, min(100, (int) $request->get('per_page', 10))));

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    private function transformTable(DiningTable $table, bool $includeOrders = false): array
    {
        $orders = $table->relationLoaded('orders') ? $table->orders : collect();
        $activeOrder = $orders->first(fn ($order) => ! in_array($order->status, ['completed', 'cancelled', 'void'], true));

        return [
            'id' => $table->id,
            'table_number' => $table->table_number,
            'name' => $table->name ?: ('Table ' . $table->table_number),
            'display_name' => $table->display_name,
            'capacity' => (int) $table->capacity,
            'section' => $table->section,
            'status' => $table->status,
            'operational_status' => TableStatusHelper::operationalStatus($table),
            'assigned_waiter_id' => $table->assigned_waiter_id,
            'is_active' => (bool) $table->is_active,
            'is_public' => (bool) ($table->is_public ?? true),
            'sort_order' => (int) ($table->sort_order ?? 0),
            'waiters' => $table->relationLoaded('waiters') ? $table->waiters->map(fn ($waiter) => [
                'id' => $waiter->id,
                'name' => $waiter->name,
            ])->values()->all() : [],
            'active_order' => $activeOrder ? [
                'id' => $activeOrder->id,
                'order_number' => $activeOrder->order_number,
                'status' => $activeOrder->status,
                'waiter_id' => $activeOrder->waiter_id,
                'bill_status' => optional($activeOrder->bill)->status,
                'bill_balance' => optional($activeOrder->bill)->balance,
            ] : null,
            'active_orders_count' => $table->relationLoaded('orders')
                ? $table->orders->whereNotIn('status', ['completed', 'cancelled', 'void'])->count()
                : $table->orders()->whereNotIn('status', ['completed', 'cancelled', 'void'])->count(),
            'orders' => $includeOrders ? $orders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'ordered_at' => $order->ordered_at,
                'waiter' => $order->relationLoaded('waiter') && $order->waiter ? [
                    'id' => $order->waiter->id,
                    'name' => $order->waiter->name,
                ] : null,
                'bill' => $order->relationLoaded('bill') && $order->bill ? [
                    'status' => $order->bill->status,
                    'paid_amount' => $order->bill->paid_amount,
                    'balance' => $order->bill->balance,
                ] : null,
            ])->values()->all() : null,
        ];
    }
}
