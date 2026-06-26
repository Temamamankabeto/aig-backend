<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function dailySales(Request $request)
    {
        $data = $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $data['date'] ?? now()->toDateString();

        $row = DB::table('bills')
            ->whereDate('paid_at', $date)
            ->where('status', 'paid')
            ->selectRaw('
                COUNT(*) as bills_count,
                SUM(subtotal) as subtotal_sum,
                SUM(tax) as tax_sum,
                SUM(service_charge) as service_charge_sum,
                SUM(discount) as discount_sum,
                SUM(total) as total_sum
            ')
            ->first();

        $methods = DB::table('payments')
            ->whereDate('paid_at', $date)
            ->where('status', 'paid')
            ->select('method', DB::raw('SUM(amount) as amount_sum'), DB::raw('COUNT(*) as tx_count'))
            ->groupBy('method')
            ->orderByDesc('amount_sum')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'sales' => $row,
                'payment_methods' => $methods,
            ]
        ]);
    }

    public function topItems(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|in:qty,revenue',
        ]);

        $limit = $data['limit'] ?? 10;
        $sort = $data['sort'] ?? 'revenue';

        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('bills as b', 'b.order_id', '=', 'o.id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->where('b.status', 'paid');

        if (!empty($data['date_from'])) $q->whereDate('b.paid_at', '>=', $data['date_from']);
        if (!empty($data['date_to'])) $q->whereDate('b.paid_at', '<=', $data['date_to']);

        $rows = $q->selectRaw('
                mi.id as menu_item_id,
                mi.name as menu_item_name,
                mi.type as menu_item_type,
                SUM(oi.quantity) as total_qty,
                SUM(oi.line_total) as total_revenue
            ')
            ->groupBy('mi.id', 'mi.name', 'mi.type')
            ->orderByDesc($sort === 'qty' ? 'total_qty' : 'total_revenue')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function kitchenPerformance(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $q = DB::table('kitchen_tickets')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at');

        if (!empty($data['date_from'])) $q->whereDate('created_at', '>=', $data['date_from']);
        if (!empty($data['date_to'])) $q->whereDate('created_at', '<=', $data['date_to']);

        $summary = $q->selectRaw("
                COUNT(*) as tickets_completed,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_prep_seconds
            ")
            ->first();

        $byStatus = DB::table('kitchen_tickets')
            ->when(!empty($data['date_from']), fn($qq) => $qq->whereDate('created_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($qq) => $qq->whereDate('created_at', '<=', $data['date_to']))
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'status_breakdown' => $byStatus,
            ]
        ]);
    }

    public function inventoryConsumption(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'type' => 'nullable|in:out,waste',
        ]);

        $q = DB::table('inventory_transactions as it')
            ->join('inventory_items as ii', 'ii.id', '=', 'it.inventory_item_id')
            ->whereIn('it.type', $data['type'] ? [$data['type']] : ['out','waste']);

        if (!empty($data['date_from'])) $q->whereDate('it.created_at', '>=', $data['date_from']);
        if (!empty($data['date_to'])) $q->whereDate('it.created_at', '<=', $data['date_to']);

        $rows = $q->selectRaw('
                ii.id as inventory_item_id,
                ii.name as inventory_item_name,
                ii.base_unit as base_unit,
                it.type,
                SUM(it.quantity) as total_qty,
                SUM(it.quantity * COALESCE(it.unit_cost, ii.average_purchase_price)) as cost_sum
            ')
            ->groupBy('ii.id','ii.name','ii.base_unit','it.type')
            ->orderByDesc('cost_sum')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function cashierShifts(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $q = DB::table('cash_shifts as cs')
            ->leftJoin('users as u', 'u.id', '=', 'cs.cashier_id')
            ->when(!empty($data['date_from']), fn($qq) => $qq->whereDate('cs.opened_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn($qq) => $qq->whereDate('cs.opened_at', '<=', $data['date_to']))
            ->selectRaw('
                cs.id as shift_id,
                cs.status,
                cs.opened_at,
                cs.closed_at,
                cs.opening_cash,
                cs.closing_cash,
                u.id as cashier_id,
                u.name as cashier_name
            ')
            ->orderByDesc('cs.id');

        $shifts = $q->get();
        $shiftIds = $shifts->pluck('shift_id')->all();

        $payments = DB::table('payments')
            ->whereIn('cash_shift_id', $shiftIds)
            ->where('status', 'paid')
            ->selectRaw('cash_shift_id, method, SUM(amount) as amount_sum, COUNT(*) as tx_count')
            ->groupBy('cash_shift_id','method')
            ->get()
            ->groupBy('cash_shift_id');

        $result = $shifts->map(function ($s) use ($payments) {
            return [
                'shift_id' => $s->shift_id,
                'status' => $s->status,
                'opened_at' => $s->opened_at,
                'closed_at' => $s->closed_at,
                'opening_cash' => $s->opening_cash,
                'closing_cash' => $s->closing_cash,
                'cashier_id' => $s->cashier_id,
                'cashier_name' => $s->cashier_name,
                'payments' => $payments[$s->shift_id] ?? [],
            ];
        });

        return response()->json(['success' => true, 'data' => $result]);
    }
}