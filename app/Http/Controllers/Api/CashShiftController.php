<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Services\AuditLogger;
use App\Services\CashShiftService;
use Illuminate\Http\Request;

class CashShiftController extends Controller
{
    public function __construct(
        private CashShiftService $cashShiftService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', CashShift::class);
        $q = CashShift::query()->with('cashier')->latest('id');
        if ($request->filled('status')) $q->where('status', $request->string('status'));
        if ($request->filled('cashier_id')) $q->where('cashier_id', $request->integer('cashier_id'));
        return response()->json(['success' => true, 'data' => $q->paginate((int)($request->get('per_page', 20)))]);
    }

    public function current(Request $request)
    {
        $this->authorize('current', CashShift::class);
        $shift = CashShift::where('cashier_id', $request->user()->id)->where('status', 'open')->latest('id')->first();
        return response()->json(['success' => true, 'data' => $shift ? $this->cashShiftService->withSummary($shift) : null]);
    }

    public function show($id)
    {
        $row = CashShift::with('cashier')->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $this->cashShiftService->withSummary($row)]);
    }

    public function open(Request $request)
    {
        $this->authorize('open', CashShift::class);
        $data = $request->validate(['opening_cash' => 'required|numeric|min:0']);

        try {
            $row = $this->cashShiftService->open((int) $request->user()->id, $data);
            $this->auditLogger->log($request, $request->user()->id, 'CashShift', $row['id'] ?? null, 'cash_shift_opened', null, $row, 'Cash shift opened.');
            return response()->json(['success' => true, 'data' => $row], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function close(Request $request, $id)
    {
        $shift = CashShift::findOrFail($id);
        $this->authorize('close', $shift);
        $data = $request->validate(['closing_cash' => 'required|numeric|min:0']);

        try {
            $before = $shift->toArray();
            $row = $this->cashShiftService->close((int) $id, $data);
            $this->auditLogger->log($request, $request->user()->id, 'CashShift', (int) $id, 'cash_shift_closed', $before, $row, 'Cash shift closed.');
            return response()->json(['success' => true, 'data' => $row]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
