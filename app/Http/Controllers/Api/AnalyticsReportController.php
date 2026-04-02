<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsReportController extends Controller
{
    public function salesAnalytics(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'group_by' => 'nullable|in:day,week,month',
        ]);

        $this->authorize('viewAny', \App\Models\Bill::class);

        $groupBy = $data['group_by'] ?? 'day';
        $driver = DB::getDriverName();

        $groupExpr = match ($groupBy) {
            'month' => $driver === 'sqlite' ? "strftime('%Y-%m', paid_at)" : "DATE_FORMAT(paid_at, '%Y-%m')",
            'week' => $driver === 'sqlite' ? "strftime('%Y-W%W', paid_at)" : "DATE_FORMAT(paid_at, '%x-W%v')",
            default => $driver === 'sqlite' ? "date(paid_at)" : "DATE(paid_at)",
        };

        $base = DB::table('bills')->whereIn('status', ['paid', 'partial']);
        if (!empty($data['date_from'])) $base->whereDate('paid_at', '>=', $data['date_from']);
        if (!empty($data['date_to'])) $base->whereDate('paid_at', '<=', $data['date_to']);

        $summary = (clone $base)->selectRaw('COUNT(*) as bills_count, COALESCE(SUM(subtotal),0) as subtotal_sum, COALESCE(SUM(tax),0) as tax_sum, COALESCE(SUM(service_charge),0) as service_charge_sum, COALESCE(SUM(discount),0) as discount_sum, COALESCE(SUM(total),0) as total_sum, COALESCE(SUM(paid_amount),0) as collected_sum, COALESCE(SUM(balance),0) as outstanding_sum')->first();

        $timeline = (clone $base)
            ->selectRaw("{$groupExpr} as period, COUNT(*) as bills_count, COALESCE(SUM(total),0) as total_sales, COALESCE(SUM(paid_amount),0) as collected_sales")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $paymentMethods = DB::table('payments')
            ->where('status', 'paid')
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('paid_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('paid_at', '<=', $data['date_to']))
            ->selectRaw('method, COUNT(*) as tx_count, COALESCE(SUM(amount),0) as amount_sum')
            ->groupBy('method')
            ->orderByDesc('amount_sum')
            ->get();

        return response()->json(['success' => true, 'data' => compact('summary', 'timeline', 'paymentMethods')]);
    }

    public function itemPopularity(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|in:qty,revenue,orders',
            'direction' => 'nullable|in:asc,desc',
        ]);

        $this->authorize('viewAny', \App\Models\Order::class);

        $limit = (int) ($data['limit'] ?? 20);
        $sort = $data['sort'] ?? 'qty';
        $sortCol = match ($sort) {
            'revenue' => 'total_revenue',
            'orders' => 'order_count',
            default => 'total_qty',
        };
        $direction = $data['direction'] ?? 'desc';

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('menu_categories as mc', 'mc.id', '=', 'mi.category_id')
            ->whereIn('o.status', ['served', 'delivered', 'completed'])
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('o.ordered_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('o.ordered_at', '<=', $data['date_to']))
            ->selectRaw('mi.id as menu_item_id, mi.name as menu_item_name, mi.type as menu_item_type, mc.name as category_name, SUM(oi.quantity) as total_qty, COALESCE(SUM(oi.line_total),0) as total_revenue, COUNT(DISTINCT o.id) as order_count')
            ->groupBy('mi.id', 'mi.name', 'mi.type', 'mc.name')
            ->orderBy($sortCol, $direction)
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function shiftReconciliationSummary(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'status' => 'nullable|in:open,closed',
        ]);

        $this->authorize('viewAny', \App\Models\CashShift::class);

        $shifts = DB::table('cash_shifts as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.cashier_id')
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('cs.opened_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('cs.opened_at', '<=', $data['date_to']))
            ->when(!empty($data['status']), fn($q) => $q->where('cs.status', $data['status']))
            ->selectRaw('cs.id as shift_id, cs.status, cs.opened_at, cs.closed_at, cs.opening_cash, cs.closing_cash, u.id as cashier_id, u.name as cashier_name')
            ->orderByDesc('cs.id')
            ->get();

        $shiftIds = $shifts->pluck('shift_id')->all();

        $payments = empty($shiftIds) ? collect() : DB::table('payments')
            ->whereIn('cash_shift_id', $shiftIds)
            ->where('status', 'paid')
            ->selectRaw('cash_shift_id, method, COUNT(*) as tx_count, COALESCE(SUM(amount),0) as amount_sum')
            ->groupBy('cash_shift_id', 'method')
            ->get()
            ->groupBy('cash_shift_id');

        $rows = $shifts->map(function ($shift) use ($payments) {
            $byMethod = collect($payments[$shift->shift_id] ?? []);
            $cash = (float) optional($byMethod->firstWhere('method', 'cash'))->amount_sum;
            $transfer = (float) optional($byMethod->firstWhere('method', 'transfer'))->amount_sum;
            $expectedCash = round((float) $shift->opening_cash + $cash, 2);
            $actualCash = $shift->closing_cash !== null ? round((float) $shift->closing_cash, 2) : null;

            return [
                'shift_id' => $shift->shift_id,
                'status' => $shift->status,
                'opened_at' => $shift->opened_at,
                'closed_at' => $shift->closed_at,
                'cashier_id' => $shift->cashier_id,
                'cashier_name' => $shift->cashier_name,
                'opening_cash' => round((float) $shift->opening_cash, 2),
                'closing_cash' => $actualCash,
                'expected_cash' => $expectedCash,
                'variance' => $actualCash === null ? null : round($actualCash - $expectedCash, 2),
                'payment_breakdown' => $byMethod->values(),
                'total_payments' => round((float) $byMethod->sum('amount_sum'), 2),
                'cash_payments' => round($cash, 2),
                'transfer_payments' => round($transfer, 2),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function paymentMethodSummary(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $this->authorize('viewAny', \App\Models\Payment::class);

        $rows = DB::table('payments')
            ->whereIn('status', ['paid', 'refunded'])
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('paid_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('paid_at', '<=', $data['date_to']))
            ->selectRaw('method, COUNT(*) as tx_count, COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END),0) as paid_sum, COALESCE(SUM(CASE WHEN status = "refunded" THEN amount ELSE 0 END),0) as refunded_sum')
            ->groupBy('method')
            ->orderByDesc('paid_sum')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function cashierPerformance(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $this->authorize('viewAny', \App\Models\Payment::class);

        $rows = DB::table('payments as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->where('p.status', 'paid')
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('p.paid_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('p.paid_at', '<=', $data['date_to']))
            ->selectRaw('u.id as cashier_id, u.name as cashier_name, COUNT(*) as tx_count, COALESCE(SUM(p.amount),0) as amount_sum, COALESCE(SUM(CASE WHEN p.method = "cash" THEN p.amount ELSE 0 END),0) as cash_sum, COALESCE(SUM(CASE WHEN p.method = "transfer" THEN p.amount ELSE 0 END),0) as transfer_sum')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('amount_sum')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function refundSummary(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $this->authorize('viewAny', \App\Models\RefundRequest::class);

        $summary = DB::table('refund_requests')
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('requested_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('requested_at', '<=', $data['date_to']))
            ->selectRaw('COUNT(*) as total_requests, COALESCE(SUM(amount),0) as total_amount, COALESCE(SUM(CASE WHEN status = "approved" THEN amount ELSE 0 END),0) as approved_amount, COALESCE(SUM(CASE WHEN status = "processed" THEN amount ELSE 0 END),0) as processed_amount')
            ->first();

        $byStatus = DB::table('refund_requests')
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('requested_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('requested_at', '<=', $data['date_to']))
            ->selectRaw('status, COUNT(*) as request_count, COALESCE(SUM(amount),0) as amount_sum')
            ->groupBy('status')
            ->get();

        return response()->json(['success' => true, 'data' => compact('summary', 'byStatus')]);
    }

    public function categorySales(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $this->authorize('viewAny', \App\Models\Order::class);

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('menu_categories as mc', 'mc.id', '=', 'mi.category_id')
            ->whereIn('o.status', ['served', 'delivered', 'completed'])
            ->when(!empty($data['date_from']), fn($q) => $q->whereDate('o.ordered_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($q) => $q->whereDate('o.ordered_at', '<=', $data['date_to']))
            ->selectRaw('mc.id as category_id, COALESCE(mc.name, "Uncategorized") as category_name, COUNT(DISTINCT o.id) as order_count, COALESCE(SUM(oi.quantity),0) as total_qty, COALESCE(SUM(oi.line_total),0) as total_revenue')
            ->groupBy('mc.id', 'mc.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
