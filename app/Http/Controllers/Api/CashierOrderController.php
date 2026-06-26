<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\BarTicket;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\InventoryDeductionService;
use App\Services\WaiterOrderService;
use App\Services\CreditOrderService;
use App\Models\CreditAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CashierOrderController extends Controller
{
    public function __construct(
        private WaiterOrderService $waiterOrderService,
        private InventoryDeductionService $inventoryDeductionService,
        private CreditOrderService $creditOrderService
    ) {
    }

    /**
     * List cashier orders
     * GET /cashier/orders
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::with([
            'table',
            'waiter',
            'bill',
            'items.menuItem',
        ])->latest('ordered_at');

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if (
            $request->filled('status') &&
            in_array($request->status, [
                'pending',
                'confirmed',
                'preparing',
                'ready',
                'served',
                'completed',
                'cancelled',
            ], true)
        ) {
            $query->where('status', $request->status);
        }

        if (
            $request->filled('order_type') &&
            in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
        ) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('ordered_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('ordered_at', '<=', $request->date_to);
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Show single order
     * GET /cashier/orders/{id}
     */
    public function show($id)
    {
        $this->authorize('viewAny', Order::class);

        $order = Order::with([
            'table',
            'creator',
            'waiter',
            'items.menuItem',
            'bill.payments',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Menu list for cashier POS order page
     * GET /cashier/orders/menu
     */
    public function menu(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $query = MenuItem::query()
            ->with('category')
            ->where('is_active', true)
            ->where('is_available', true);

        if ($request->filled('type') && in_array($request->type, ['food', 'drink'], true)) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($cat) use ($search) {
                        $cat->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $items = $query
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'type' => $item->type,
                    'price' => (float) $item->price,
                    'description' => $item->description,
                    'category' => optional($item->category)->name,
                    'image_url' => $item->image
                        ? url('storage/' . $item->image)
                        : ($item->image_url ?? null),
                    'is_available' => (bool) $item->is_available,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Tables list for cashier POS
     * GET /cashier/orders/tables
     */
    public function tables(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $query = DiningTable::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('table_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('section', 'like', "%{$search}%");
            });
        }

        if ($request->filled('section')) {
            $section = trim((string) $request->section);
            $query->where('section', 'like', "%{$section}%");
        }

        $tables = $query
            ->orderBy('table_number')
            ->get()
            ->map(function ($table) {
                return [
                    'id' => $table->id,
                    'table_number' => (string) $table->table_number,
                    'name' => 'Table ' . $table->table_number,
                    'capacity' => $table->capacity,
                    'section' => $table->section ?? null,
                    'status' => $table->status,
                    'is_active' => (bool) $table->is_active,
                    'is_available' => $table->status === 'available',
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'All tables fetched successfully',
            'data' => $tables,
        ]);
    }

    /**
     * Optional normal store
     * not used by current cashier route, but safe to keep
     */
    public function store(StoreOrderRequest $request)
    {
        $this->authorize('create', Order::class);

        try {
            $order = $this->waiterOrderService->createOrder(
                $request->validated(),
                (int) auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Confirm a pending cashier order after 5 minutes and deduct inventory.
     * POST /cashier/orders/{id}/confirm
     *
     * Kept only for old pending orders created before the new cashier logic.
     * New cashier orders should already be confirmed at creation time.
     */
    public function confirm($id)
    {
        $order = Order::findOrFail($id);
        $this->authorize('update', $order);

        return DB::transaction(function () use ($id) {
            $order = Order::with(['items.menuItem', 'table', 'bill'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be confirmed.',
                ], 422);
            }

            if ($order->created_at && now()->lt($order->created_at->copy()->addMinutes(5))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cashier orders can be confirmed only after 5 minutes from creation.',
                    'confirmable_at' => $order->created_at->copy()->addMinutes(5)->toDateTimeString(),
                ], 422);
            }

            $order->update([
                'status' => 'confirmed',
            ]);

            $orderItems = OrderItem::where('order_id', $order->id)->lockForUpdate()->get();

            foreach ($orderItems as $orderItem) {
                $orderItem->update([
                    'item_status' => 'confirmed',
                ]);

                $this->inventoryDeductionService->deductForOrderItem($orderItem->fresh(), (int) auth()->id());
            }

            KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')
                    ->from('order_items')
                    ->where('order_id', $order->id)
                    ->where('station', 'kitchen');
            })->update([
                'status' => 'confirmed',
            ]);

            BarTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')
                    ->from('order_items')
                    ->where('order_id', $order->id)
                    ->where('station', 'bar');
            })->update([
                'status' => 'confirmed',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cashier order confirmed successfully.',
                'data' => $order->fresh(['items.menuItem', 'bill', 'table', 'waiter', 'creator']),
            ]);
        });
    }

    /**
     * Cashier POS order store
     * POST /cashier/orders
     */
    public function cashierStore(StoreOrderRequest $request)
    {
        $this->authorize('create', Order::class);

        try {
            $validated = $request->validated();

            if (empty($validated['waiter_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Waiter is required for cashier order entry.',
                    'errors' => [
                        'waiter_id' => ['The waiter field is required.'],
                    ],
                ], 422);
            }

            $validated['order_type'] = $validated['order_type'] ?? 'takeaway';
            $validated['table_id'] = $validated['order_type'] === 'dine_in'
                ? ($validated['table_id'] ?? null)
                : null;

            $validated['customer_name'] = $validated['customer_name'] ?? 'Guest';
            $validated['customer_phone'] = $validated['customer_phone'] ?? null;
            $validated['customer_address'] = $validated['customer_address'] ?? null;

            $isCreditOrder = ($validated['payment_type'] ?? null) === 'credit';

            if ($isCreditOrder && empty($validated['credit_account_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credit account is required for credit orders.',
                    'errors' => [
                        'credit_account_id' => ['The credit account field is required.'],
                    ],
                ], 422);
            }

            // This makes WaiterOrderService treat it as cashier order:
            // - status confirmed
            // - item_status confirmed
            // - ticket status confirmed
            // - stock checked and deducted immediately
            $validated['_source'] = 'cashier';

            $order = $this->waiterOrderService->createOrder(
                $validated,
                (int) auth()->id()
            );

            $order->load('bill');

            $creditOrder = $order->creditOrder ?? $order->bill?->creditOrder ?? null;

            return response()->json([
                'success' => true,
                'message' => $isCreditOrder
                    ? 'Cashier credit order created successfully'
                    : 'Cashier order created and confirmed successfully',
                'data' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_type' => $isCreditOrder ? 'credit' : ($order->payment_type ?? 'regular'),
                    'credit_status' => $creditOrder->status ?? $order->credit_status ?? null,
                    'credit_order' => $creditOrder,
                    'bill_id' => $order->bill->id ?? null,
                    'bill' => $order->bill ? [
                        'id' => $order->bill->id,
                        'bill_number' => $order->bill->bill_number ?? null,
                        'total' => $order->bill->total ?? null,
                    ] : null,
                ],
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}