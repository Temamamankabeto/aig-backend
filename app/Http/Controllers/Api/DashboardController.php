<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BarTicket;
use App\Models\CashShift;
use App\Models\DiningTable;
use App\Models\InventoryItem;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CashShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{

    public function customerDashboard(Request $request)
    {
        return response()->json([
            "success" => true,
            "role" => "customer",
            "message" => "Customer Dashboard",
            "user" => $request->user()
        ]);
    }

    public function waiterDashboard(Request $request)
    {
        Gate::authorize('dashboard.waiter');

        $orders = Order::query()
            ->where('waiter_id', $request->user()->id)
            ->whereDate('ordered_at', now()->toDateString());

        return response()->json([
            "success" => true,
            "message" => "Waiter dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'today_orders' => (clone $orders)->count(),
                    'pending_orders' => (clone $orders)->whereIn('status', ['pending', 'submitted', 'confirmed', 'preparing'])->count(),
                    'ready_orders' => (clone $orders)->where('status', 'ready')->count(),
                    'served_orders' => (clone $orders)->whereIn('status', ['served', 'completed'])->count(),
                ],
            ],
            "meta" => null,
        ]);
    }

    public function cashierDashboard(
        Request $request,
        CashShiftService $cashShiftService
    )
    {
        Gate::authorize('cashier.dashboard');

        $cashierId = (int) $request->user()->id;
        $today = now()->toDateString();

        $currentShift = CashShift::query()
            ->where('cashier_id', $cashierId)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        $todayOrders = Order::query()
            ->where('created_by', $cashierId)
            ->whereDate('ordered_at', $today)
            ->whereNotIn('status', ['cancelled', 'void']);

        $todayPayments = Payment::query()
            ->where('received_by', $cashierId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $today);

        $paymentMethods = (clone $todayPayments)
            ->select('method')
            ->selectRaw('COUNT(*) as transactions')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->groupBy(fn ($row) => trim((string) $row->method) !== ''
                ? (string) $row->method
                : 'cash')
            ->map(fn ($rows, $method) => [
                'method' => (string) $method,
                'transactions' => (int) $rows->sum('transactions'),
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->sortByDesc('amount')
            ->values();

        $orderStatuses = (clone $todayOrders)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->total]);

        $pendingBills = Bill::query()
            ->where('issued_by', $cashierId)
            ->whereIn('status', ['issued', 'partial']);

        $recentOrders = Order::query()
            ->with([
                'table:id,table_number',
                'waiter:id,name',
                'bill:id,order_id,status,balance',
            ])
            ->where('created_by', $cashierId)
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_type' => $order->order_type,
                'table' => $order->table?->table_number,
                'waiter' => $order->waiter?->name,
                'status' => $order->status,
                'payment_type' => $order->payment_type,
                'payment_status' => $order->payment_status,
                'total' => round((float) $order->total, 2),
                'ordered_at' => $order->ordered_at ?? $order->created_at,
                'bill_id' => $order->bill?->id,
                'bill_status' => $order->bill?->status,
                'balance' => round((float) ($order->bill?->balance ?? 0), 2),
            ])
            ->values();

        return response()->json([
            "success" => true,
            "message" => "Cashier dashboard loaded successfully",
            "role" => "cashier",
            "data" => [
                'business_date' => $today,
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ],
                'current_shift' => $currentShift
                    ? $cashShiftService->withSummary($currentShift)
                    : null,
                'summary' => [
                    'orders' => (int) (clone $todayOrders)->count(),
                    'gross_order_value' => round((float) (clone $todayOrders)->sum('total'), 2),
                    'payments_collected' => round((float) (clone $todayPayments)->sum('amount'), 2),
                    'paid_transactions' => (int) (clone $todayPayments)->count(),
                    'cash_collected' => round((float) (clone $todayPayments)->where('method', 'cash')->sum('amount'), 2),
                    'credit_orders' => (int) (clone $todayOrders)->where('payment_type', 'credit')->count(),
                    'pending_bills' => (int) (clone $pendingBills)->count(),
                    'pending_amount' => round((float) (clone $pendingBills)->sum('balance'), 2),
                ],
                'order_statuses' => $orderStatuses,
                'payment_methods' => $paymentMethods,
                'recent_orders' => $recentOrders,
            ],
            "meta" => null,
        ]);
    }

    public function barDashboard(Request $request)
    {
        Gate::authorize('bar.dashboard');

        $tickets = BarTicket::query()->whereDate('created_at', now()->toDateString());

        return response()->json([
            "success" => true,
            "message" => "Bar dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'today_tickets' => (clone $tickets)->count(),
                    'pending_tickets' => (clone $tickets)->whereIn('status', ['pending', 'confirmed'])->count(),
                    'preparing_tickets' => (clone $tickets)->whereIn('status', ['accepted', 'preparing'])->count(),
                    'ready_tickets' => (clone $tickets)->where('status', 'ready')->count(),
                ],
            ],
            "meta" => null,
        ]);
    }

    public function kitchenDashboard(Request $request)
    {
        Gate::authorize('kitchen.dashboard');

        $tickets = KitchenTicket::query()->whereDate('created_at', now()->toDateString());

        return response()->json([
            "success" => true,
            "message" => "Kitchen dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'today_tickets' => (clone $tickets)->count(),
                    'pending_tickets' => (clone $tickets)->whereIn('status', ['pending', 'confirmed'])->count(),
                    'preparing_tickets' => (clone $tickets)->whereIn('status', ['accepted', 'preparing'])->count(),
                    'ready_tickets' => (clone $tickets)->where('status', 'ready')->count(),
                ],
            ],
            "meta" => null,
        ]);
    }

    public function foodControllerDashboard(Request $request)
    {
        Gate::authorize('food-controller.dashboard');
        return response()->json([
            "success" => true,
            "role" => "food-controller",
            "message" => "Food Controller Dashboard",
            "user" => $request->user()
        ]);
    }

    public function financeDashboard(Request $request)
    {
        Gate::authorize('finance.dashboard');

        $today = now()->toDateString();
        $payments = Payment::query()->where('status', 'paid')->whereDate('paid_at', $today);

        return response()->json([
            "success" => true,
            "message" => "Finance dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'today_collections' => round((float) (clone $payments)->sum('amount'), 2),
                    'paid_transactions' => (clone $payments)->count(),
                    'pending_refunds' => RefundRequest::query()->where('status', 'pending')->count(),
                    'outstanding_bills' => round((float) Bill::query()->whereIn('status', ['issued', 'partial'])->sum('balance'), 2),
                ],
            ],
            "meta" => null,
        ]);
    }

    public function managerDashboard(Request $request)
    {
        Gate::authorize('manager.dashboard');

        $today = now()->toDateString();
        $orders = Order::query()->whereDate('ordered_at', $today)->whereNotIn('status', ['cancelled', 'void']);

        return response()->json([
            "success" => true,
            "message" => "Manager dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'today_orders' => (clone $orders)->count(),
                    'today_sales' => round((float) (clone $orders)->where('payment_status', 'paid')->sum('total'), 2),
                    'pending_approvals' => PurchaseOrder::query()->where('status', 'food_validated')->count(),
                    'active_tables' => DiningTable::query()->where('is_active', true)->count(),
                    'low_stock_items' => InventoryItem::query()->whereColumn('current_stock', '<=', 'minimum_quantity')->count(),
                ],
            ],
            "meta" => null,
        ]);
    }

    public function generalDashboard(Request $request)
    {
        Gate::authorize('general.dashboard');

        $today = now()->toDateString();

        return response()->json([
            "success" => true,
            "message" => "General Admin dashboard loaded successfully",
            "data" => [
                'summary' => [
                    'total_users' => User::query()->count(),
                    'active_users' => User::query()->where('is_active', true)->count(),
                    'today_orders' => Order::query()->whereDate('ordered_at', $today)->count(),
                    'today_collections' => round((float) Payment::query()->where('status', 'paid')->whereDate('paid_at', $today)->sum('amount'), 2),
                    'pending_purchase_approvals' => PurchaseOrder::query()->whereIn('status', ['submitted', 'food_validated'])->count(),
                    'low_stock_items' => InventoryItem::query()->whereColumn('current_stock', '<=', 'minimum_quantity')->count(),
                ],
            ],
            "meta" => null,
        ]);
    }

}
