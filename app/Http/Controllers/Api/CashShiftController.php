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
    
        $query = CashShift::with('cashier')->latest('id');
    
        if (
            $request->filled('status') &&
            in_array($request->status, ['open', 'closed'], true)
        ) {
            $query->where('status', $request->status);
        }
    
        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', (int) $request->cashier_id);
        }
    
        if ($request->filled('date_from')) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }
    
        if ($request->filled('date_to')) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }
    
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $shifts = $query->paginate($perPage);
    
        $data = collect($shifts->items())->map(function ($shift) {
            $summary = $this->cashShiftService->summary($shift);
    
            $closingCash = (float) ($shift->closing_cash ?? 0);
            $expectedCash = (float) ($summary['expected_cash'] ?? 0);
    
            return [
                'id' => $shift->id,
                'status' => $shift->status,
                'cashier_name' => $shift->cashier?->name,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'opening_cash' => round((float) $shift->opening_cash, 2),
                'closing_cash' => $shift->closing_cash !== null
                    ? round((float) $shift->closing_cash, 2)
                    : null,
                'expected_cash' => round($expectedCash, 2),
                'variance' => $shift->closing_cash !== null
                    ? round($closingCash - $expectedCash, 2)
                    : null,
            ];
        })->values();
    
        $currentShift = CashShift::where('cashier_id', $request->user()->id)
            ->latest('id')
            ->first();
    
        return response()->json([
            'success' => true,
            'current_status' => [
                'status' => $currentShift?->status ?? 'no_shift',
                'shift_id' => $currentShift?->id,
                'opened_at' => $currentShift?->opened_at,
                'closed_at' => $currentShift?->closed_at,
            ],
            'data' => $data,
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
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
