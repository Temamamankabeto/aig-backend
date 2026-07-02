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

    private function buildShiftReport(CashShift $shift): array
    {
        $shift->loadMissing('cashier');
        $summary = $this->cashShiftService->summary($shift);

        $paymentBreakdown = Payment::query()
            ->where('cash_shift_id', $shift->id)
            ->where('status', 'paid')
            ->select('method', DB::raw('COUNT(*) as transactions'), DB::raw('COALESCE(SUM(amount),0) as amount'))
            ->groupBy('method')
            ->get();

        $grossSales = (float) ($summary['total_payments'] ?? 0) + (float) ($summary['credit_amount'] ?? 0);
        $cashSales = (float) ($summary['cash_payments'] ?? 0);
        $creditSales = (float) ($summary['credit_amount'] ?? 0);
        $expectedCash = (float) ($summary['expected_cash'] ?? 0);
        $actualCash = $shift->closing_cash !== null ? (float) $shift->closing_cash : null;

        $orderIds = Bill::query()
            ->where(function ($query) use ($shift) {
                $query->where('cash_shift_id', $shift->id)
                    ->orWhereHas('payments', fn ($payment) => $payment->where('cash_shift_id', $shift->id));
            })
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique()
            ->values();

        $totalOrders = $orderIds->count();
        $voidedOrders = Order::whereIn('id', $orderIds)->whereIn('status', ['cancelled', 'void'])->count();

        $billTotals = Bill::whereIn('order_id', $orderIds)
            ->selectRaw('COALESCE(SUM(tax),0) as vat, COALESCE(SUM(service_charge),0) as service_charge, COALESCE(SUM(discount),0) as discounts')
            ->first();

        $categorySales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menu_items.category_id')
            ->whereIn('orders.id', $orderIds)
            ->selectRaw("COALESCE(menu_categories.name, 'Uncategorized') as category, COALESCE(SUM(order_items.line_total),0) as amount, COALESCE(SUM(order_items.quantity),0) as quantity")
            ->groupBy('menu_categories.name')
            ->orderByDesc('amount')
            ->get();

        $itemSales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->whereIn('orders.id', $orderIds)
            ->selectRaw('menu_items.name as item_name, COALESCE(SUM(order_items.quantity),0) as quantity, COALESCE(SUM(order_items.line_total),0) as amount')
            ->groupBy('menu_items.name')
            ->orderByDesc('amount')
            ->limit(100)
            ->get();

        return array_merge($shift->toArray(), [
            'cashier' => $shift->cashier,
            'cashier_name' => $shift->cashier?->name,
            'branch' => config('app.name', 'Restaurant'),
            'open_time' => $shift->opened_at,
            'current_time' => now(),
            'close_time' => $shift->closed_at,
            'total_orders' => $totalOrders,
            'voided_orders' => $voidedOrders,
            'refunded_orders' => (int) ($summary['cash_refunds'] > 0 ? 1 : 0),
            'discounts' => round((float) ($billTotals->discounts ?? 0), 2),
            'vat' => round((float) ($billTotals->vat ?? 0), 2),
            'service_charge' => round((float) ($billTotals->service_charge ?? 0), 2),
            'cash_sales' => round($cashSales, 2),
            'credit_sales' => round($creditSales, 2),
            'card_sales' => round((float) ($summary['card_payments'] ?? 0), 2),
            'mobile_money_sales' => round((float) ($summary['mobile_payments'] ?? 0), 2),
            'bank_sales' => round((float) ($summary['bank_payments'] ?? $summary['transfer_payments'] ?? 0), 2),
            'gross_sales' => round($grossSales, 2),
            'net_sales' => round($grossSales - (float) ($billTotals->discounts ?? 0), 2),
            'opening_cash' => round((float) $shift->opening_cash, 2),
            'expected_cash' => round($expectedCash, 2),
            'actual_cash' => $actualCash !== null ? round($actualCash, 2) : null,
            'cash_difference' => $actualCash !== null ? round($actualCash - $expectedCash, 2) : null,
            'drawer_cash' => round($expectedCash, 2),
            'payment_method_breakdown' => $paymentBreakdown,
            'category_sales' => $categorySales,
            'item_sales' => $itemSales,
            'summary' => array_merge($summary, [
                'variance' => $actualCash !== null ? round($actualCash - $expectedCash, 2) : null,
            ]),
            'final_shift_status' => $shift->status,
        ]);
    }

    public function xReport(Request $request)
    {
        $this->authorize('current', CashShift::class);

        $shift = CashShift::query()
            ->with('cashier')
            ->when(
                $request->filled('shift_id'),
                fn ($q) => $q->where('id', $request->shift_id)->where('status', 'open'),
                fn ($q) => $q->where('cashier_id', $request->user()->id)
                    ->where('status', 'open')
                    ->latest('id')
            )
            ->first();

        if (! $shift) {
            return response()->json([
                'success' => false,
                'message' => 'No open shift found. X-Report is available only while a shift is open.',
            ], 404);
        }

        $report = $this->buildShiftReport($shift);

        if (Schema::hasTable('cashier_shift_reports')) {
            DB::table('cashier_shift_reports')->insert([
                'cash_shift_id' => $shift->id,
                'generated_by' => $request->user()->id,
                'report_type' => 'x_report',
                'payload' => json_encode($report),
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'X-Report generated. Totals were not reset.',
            'data' => $report,
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

        $existing = null;
        if ($shift->status === 'closed' && Schema::hasTable('cashier_shift_reports')) {
            $existing = DB::table('cashier_shift_reports')
                ->where('cash_shift_id', $shift->id)
                ->where('report_type', 'z_report')
                ->orderByDesc('id')
                ->first();
        }

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Z-Report already generated for this closed shift.',
                'data' => json_decode($existing->payload, true),
            ]);
        }

        $report = $this->buildShiftReport($shift);

        if ($shift->status === 'closed' && Schema::hasTable('cashier_shift_reports')) {
            DB::table('cashier_shift_reports')->insert([
                'cash_shift_id' => $shift->id,
                'generated_by' => $request->user()->id,
                'report_type' => 'z_report',
                'payload' => json_encode($report),
                'generated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $shift->status === 'closed'
                ? 'Z-Report generated for closed shift.'
                : 'Z-Report preview generated. Close the shift to lock totals.',
            'data' => $report,
        ]);
    }

}