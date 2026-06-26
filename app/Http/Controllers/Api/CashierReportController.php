<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashierReportController extends Controller
{
    public function __construct(
        private CashShiftService $cashShiftService
    ) {
    }

    protected function applyDateRange($query, Request $request, string $column = 'created_at')
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->date_to);
        }

        return $query;
    }

    public function salesSummary(Request $request)
    {
        $paymentsQuery = Payment::query()->where('status', 'paid');
        $billsQuery = Bill::query();
        $ordersQuery = Order::query();

        $this->applyDateRange($paymentsQuery, $request, 'created_at');
        $this->applyDateRange($billsQuery, $request, 'created_at');
        $this->applyDateRange($ordersQuery, $request, 'ordered_at');

        $data = [
            'total_sales' => (float) $paymentsQuery->sum('amount'),
            'total_paid_payments' => (int) Payment::query()
                ->where('status', 'paid')
                ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
                ->count(),
            'total_bills' => (int) $billsQuery->count(),
            'paid_bills' => (int) Bill::query()
                ->where('status', 'paid')
                ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
                ->count(),
            'void_bills' => (int) Bill::query()
                ->where('status', 'void')
                ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
                ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
                ->count(),
            'total_orders' => (int) $ordersQuery->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function paymentMethodSummary(Request $request)
    {
        $query = Payment::query()
            ->select(
                'method',
                DB::raw('COUNT(*) as total_transactions'),
                DB::raw('COALESCE(SUM(amount),0) as total_amount')
            )
            ->groupBy('method')
            ->orderByDesc('total_amount');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $this->applyDateRange($query, $request, 'created_at');

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function shiftSummary(Request $request)
    {
        $query = CashShift::query()
            ->with(['cashier'])
            ->orderByDesc('id');

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->cashier_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $this->applyDateRange($query, $request, 'opened_at');

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $rows = $query->paginate($perPage);

        $data = collect($rows->items())->map(function (CashShift $shift) {
            $summary = $this->cashShiftService->summary($shift);
            $closingCash = $shift->closing_cash !== null ? (float) $shift->closing_cash : null;
            $expectedCash = (float) ($summary['expected_cash'] ?? 0);

            return array_merge($shift->toArray(), [
                'cashier' => $shift->cashier,
                'cashier_name' => $shift->cashier?->name,
                'summary' => array_merge($summary, [
                    'variance' => $closingCash !== null ? round($closingCash - $expectedCash, 2) : null,
                ]),
            ]);
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function cashierPerformance(Request $request)
    {
        $query = Payment::query()
            ->leftJoin('users as receivers', 'payments.received_by', '=', 'receivers.id')
            ->select(
                'payments.received_by',
                'receivers.name as cashier_name',
                DB::raw('COUNT(payments.id) as total_transactions'),
                DB::raw('COALESCE(SUM(payments.amount),0) as total_amount')
            )
            ->groupBy('payments.received_by', 'receivers.name')
            ->orderByDesc('total_amount');

        if ($request->filled('cashier_id')) {
            $query->where('payments.received_by', $request->cashier_id);
        }

        if ($request->filled('status')) {
            $query->where('payments.status', $request->status);
        }

        $this->applyDateRange($query, $request, 'payments.created_at');

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function refundSummary(Request $request)
    {
        $query = RefundRequest::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $this->applyDateRange($query, $request, 'created_at');

        $totalCount = (clone $query)->count();

        $totalAmount = Schema::hasColumn('refund_requests', 'amount')
            ? (float) (clone $query)->sum('amount')
            : null;

        $byStatus = RefundRequest::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->groupBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_requests' => $totalCount,
                'total_amount' => $totalAmount,
                'by_status' => $byStatus,
            ],
        ]);
    }

    public function voidedBills(Request $request)
    {
        $query = Bill::query()
            ->with(['order', 'payments'])
            ->where('status', 'void')
            ->orderByDesc('id');

        $this->applyDateRange($query, $request, 'created_at');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->whereHas('order', function ($oq) use ($search) {
                    $oq->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%");
                });
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function pendingPayments(Request $request)
    {
        $query = Bill::query()
            ->with(['order', 'payments'])
            ->whereIn('status', ['issued', 'partial'])
            ->orderByDesc('id');

        $this->applyDateRange($query, $request, 'created_at');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->whereHas('order', function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function xReport(Request $request)
    {
        $this->authorize('current', CashShift::class);

        $shift = CashShift::query()
            ->with('cashier')
            ->when(
                $request->filled('shift_id'),
                fn ($q) => $q->where('id', $request->shift_id),
                fn ($q) => $q->where('cashier_id', $request->user()->id)
                    ->where('status', 'open')
                    ->latest('id')
            )
            ->first();

        if (! $shift) {
            return response()->json([
                'success' => false,
                'message' => 'No open shift found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->cashShiftService->withSummary($shift),
        ]);
    }

    public function zReport(Request $request)
    {
        $request->validate([
            'shift_id' => ['required', 'integer', 'exists:cash_shifts,id'],
        ]);

        $shift = CashShift::query()
            ->with('cashier')
            ->findOrFail($request->shift_id);

        $summary = $this->cashShiftService->summary($shift);

        $closingCash = (float) ($shift->closing_cash ?? 0);
        $expectedCash = (float) ($summary['expected_cash'] ?? 0);
        $variance = round($closingCash - $expectedCash, 2);

        $data = array_merge(
            $shift->toArray(),
            [
                'cashier' => $shift->cashier,
                'summary' => array_merge($summary, [
                    'variance' => $variance,
                ]),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}