<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashShiftMovementController extends Controller
{
    public function __construct(
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request, $shiftId)
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorize('view', $shift);

        $q = CashShiftMovement::query()
            ->with('creator')
            ->where('cash_shift_id', $shift->id)
            ->latest('id');

        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        return response()->json([
            'success' => true,
            'data' => $q->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    public function store(Request $request, $shiftId)
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorize('update', $shift);

        if ($shift->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'You can only add movements to an open shift.',
            ], 422);
        }

        $data = $request->validate([
            'type' => 'required|in:opening_adjustment,refund,paid_out,cash_drop',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
            'reference_type' => 'nullable|string|max:100',
            'reference_id' => 'nullable|integer',
        ]);

        $movement = DB::transaction(function () use ($request, $shift, $data) {
            return CashShiftMovement::create([
                'cash_shift_id' => $shift->id,
                'created_by' => $request->user()->id ?? null,
                'type' => $data['type'],
                'amount' => round((float) $data['amount'], 2),
                'note' => $data['note'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
            ]);
        });

        $this->auditLogger->log(
            $request,
            $request->user()->id ?? null,
            'CashShiftMovement',
            $movement->id,
            'cash_shift_movement_created',
            null,
            $movement->toArray(),
            'Cash shift movement created.'
        );

        return response()->json([
            'success' => true,
            'data' => $movement->load('creator'),
        ], 201);
    }

    public function show(Request $request, $shiftId, $movementId)
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorize('view', $shift);

        $movement = CashShiftMovement::with('creator')
            ->where('cash_shift_id', $shift->id)
            ->findOrFail($movementId);

        return response()->json([
            'success' => true,
            'data' => $movement,
        ]);
    }

    public function destroy(Request $request, $shiftId, $movementId)
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorize('update', $shift);

        if ($shift->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete movements from an open shift.',
            ], 422);
        }

        $movement = CashShiftMovement::where('cash_shift_id', $shift->id)
            ->findOrFail($movementId);

        $before = $movement->toArray();
        $movement->delete();

        $this->auditLogger->log(
            $request,
            $request->user()->id ?? null,
            'CashShiftMovement',
            $movementId,
            'cash_shift_movement_deleted',
            $before,
            null,
            'Cash shift movement deleted.'
        );

        return response()->json([
            'success' => true,
            'message' => 'Movement deleted successfully.',
        ]);
    }
}