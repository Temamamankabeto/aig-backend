<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\AssignWaiterRequest;
use App\Http\Requests\Authz\SetTableStatusRequest;
use App\Http\Requests\Authz\StoreDiningTableRequest;
use App\Http\Requests\Authz\TransferTableRequest;
use App\Http\Requests\Authz\UpdateDiningTableRequest;
use App\Models\DiningTable;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class DiningTableController extends Controller
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', DiningTable::class);

        $q = DiningTable::query()->with('waiters:id,name');

        if ($request->filled('search')) {
            $s = trim((string) $request->get('search'));
            $q->where('table_number', 'like', "%{$s}%");
        }

        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }

        if ($request->filled('section')) {
            $q->where('section', $request->get('section'));
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', (bool) $request->boolean('is_active'));
        }

        return response()->json([
            'success' => true,
            'data' => $q->orderBy('table_number')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    public function show($id)
    {
        $table = DiningTable::with('waiters:id,name')->findOrFail($id);
        $this->authorize('view', $table);

        return response()->json(['success' => true, 'data' => $table]);
    }

    public function store(StoreDiningTableRequest $request)
    {
        $this->authorize('create', DiningTable::class);
        $data = $request->validated();

        $table = DiningTable::create([
            'table_number' => $data['table_number'],
            'capacity' => $data['capacity'] ?? 4,
            'section' => $data['section'] ?? null,
            'status' => 'available',
            'is_active' => true,
        ]);

        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_created', null, $table->toArray(), 'Dining table created.');

        return response()->json(['success' => true, 'data' => $table->load('waiters:id,name')], 201);
    }

    public function update(UpdateDiningTableRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('update', $table);
        $before = $table->toArray();
        $table->update($request->validated());
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_updated', $before, $table->fresh()->toArray(), 'Dining table updated.');

        return response()->json(['success' => true, 'data' => $table->load('waiters:id,name')]);
    }

    public function assignWaiter(AssignWaiterRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('assignWaiter', $table);

        $before = $table->load('waiters:id,name')->toArray();
        $table->waiters()->syncWithoutDetaching($request->validated()['waiter_ids']);
        $after = $table->fresh()->load('waiters:id,name')->toArray();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_waiters_assigned', $before, $after, 'Waiters assigned to table.');

        return response()->json([
            'success' => true,
            'message' => 'Waiters assigned',
            'data' => $table->fresh()->load('waiters:id,name'),
        ]);
    }

    public function transfer(TransferTableRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('transfer', $table);

        $before = $table->load('waiters:id,name')->toArray();
        $table->waiters()->sync($request->validated()['to_waiter_ids']);
        $after = $table->fresh()->load('waiters:id,name')->toArray();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_transferred', $before, $after, 'Table waiter assignment transferred.');

        return response()->json([
            'success' => true,
            'message' => 'Table transferred',
            'data' => $table->fresh()->load('waiters:id,name'),
        ]);
    }

    public function setStatus(SetTableStatusRequest $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('setStatus', $table);

        if (! $table->is_active) {
            return response()->json(['success' => false, 'message' => 'Table is inactive'], 400);
        }

        $before = $table->toArray();
        $table->status = $request->validated()['status'];
        $table->save();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_status_changed', $before, $table->fresh()->toArray(), 'Dining table status changed.');

        return response()->json(['success' => true, 'data' => $table->load('waiters:id,name')]);
    }

    public function toggleActive(Request $request, $id)
    {
        $table = DiningTable::findOrFail($id);
        $this->authorize('toggleActive', $table);
        $before = $table->toArray();
        $table->is_active = ! $table->is_active;

        if (! $table->is_active) {
            $table->assigned_waiter_id = null;
            $table->status = 'available';
        }

        $table->save();
        $this->auditLogger->log($request, $request->user()->id, 'DiningTable', $table->id, 'table_toggled', $before, $table->fresh()->toArray(), 'Dining table active flag toggled.');

        return response()->json(['success' => true, 'data' => $table->load('waiters:id,name')]);
    }
}
