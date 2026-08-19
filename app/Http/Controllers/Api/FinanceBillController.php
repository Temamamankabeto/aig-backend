<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceBillController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->can('payments.read'), 403, 'You are not authorized to view paid bills.');

        $search = trim((string) $request->input('search', ''));

        $legacyBills = DB::table('bills as bills')
            ->join('orders as orders', 'orders.id', '=', 'bills.order_id')
            ->where('bills.status', 'paid')
            ->selectRaw("CONCAT('bill-', bills.id) as row_key")
            ->addSelect([
                'bills.id',
                'bills.bill_number',
                'bills.order_id',
                'orders.order_number',
                'orders.customer_name',
                'bills.total',
                'bills.paid_amount',
                'bills.balance',
                'bills.status',
                'bills.paid_at',
                'bills.created_at',
            ]);

        if ($search !== '') {
            $legacyBills->where(function ($query) use ($search) {
                $query->where('bills.bill_number', 'like', "%{$search}%")
                    ->orWhere('orders.order_number', 'like', "%{$search}%")
                    ->orWhere('orders.customer_name', 'like', "%{$search}%");
            });
        }

        $directOrders = DB::table('orders as orders')
            ->leftJoin('bills as bills', 'bills.order_id', '=', 'orders.id')
            ->whereNull('bills.id')
            ->whereIn('orders.payment_status', ['paid', 'partially_refunded', 'refunded'])
            ->selectRaw("CONCAT('order-', orders.id) as row_key")
            ->selectRaw('orders.id as id')
            ->selectRaw('orders.order_number as bill_number')
            ->selectRaw('orders.id as order_id')
            ->addSelect(['orders.order_number', 'orders.customer_name', 'orders.total'])
            ->selectRaw('LEAST(orders.paid_amount, orders.total) as paid_amount')
            ->selectRaw('GREATEST(orders.total - LEAST(orders.paid_amount, orders.total), 0) as balance')
            ->selectRaw('orders.payment_status as status')
            ->addSelect(['orders.paid_at', 'orders.created_at']);

        if ($search !== '') {
            $directOrders->where(function ($query) use ($search) {
                $query->where('orders.order_number', 'like', "%{$search}%")
                    ->orWhere('orders.customer_name', 'like', "%{$search}%");
            });
        }

        $combined = $legacyBills->unionAll($directOrders);
        $rows = DB::query()
            ->fromSub($combined, 'paid_bills')
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->paginate(min(max((int) $request->input('per_page', 100), 1), 100));

        $data = collect($rows->items())->map(function ($row) {
            return [
                'id' => $row->row_key,
                'bill_number' => $row->bill_number,
                'total' => $row->total,
                'paid_amount' => $row->paid_amount,
                'balance' => $row->balance,
                'status' => $row->status,
                'paid_at' => $row->paid_at,
                'created_at' => $row->created_at,
                'order' => [
                    'id' => $row->order_id,
                    'order_number' => $row->order_number,
                    'customer_name' => $row->customer_name,
                ],
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Paid bills retrieved successfully.',
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
