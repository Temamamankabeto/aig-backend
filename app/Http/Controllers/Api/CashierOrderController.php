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
use App\Models\Bill;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\CreditAgreement;
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

        $cashierId = (int) $request->user()->id;

        $query = Order::with([
            'table',
            'creator.roles',
            'waiter',
            'bill',
            'items.menuItem.category',
        ])
            ->where(function ($scope) use ($cashierId) {
                $scope->where('created_by', $cashierId)
                    ->orWhereHas('creator.roles', function ($roleQuery) {
                        $roleQuery->whereRaw('LOWER(name) = ?', ['waiter']);
                    });
            })
            ->latest('ordered_at');

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

        if ($request->filled('payment_status')) {
            $paymentStatus = (string) $request->payment_status;
            $query->whereHas('bill', function ($billQuery) use ($paymentStatus) {
                $billQuery->where('status', $paymentStatus);
            });
        }

        if ($request->filled('payment_type')) {
            $paymentType = (string) $request->payment_type;
            if ($paymentType === 'cash') {
                $query->where(function ($paymentQuery) {
                    $paymentQuery->whereNull('payment_type')
                        ->orWhereIn('payment_type', ['cash', 'regular', 'card', 'mobile', 'transfer']);
                });
            } elseif ($paymentType === 'credit') {
                $query->where('payment_type', 'credit');
            }
        }

        if ($request->filled('waiter_id')) {
            $query->where('waiter_id', (int) $request->waiter_id);
        }

        if ($request->filled('period')) {
            match ((string) $request->period) {
                'today' => $query->whereDate('ordered_at', today()),
                'this_week' => $query->whereBetween('ordered_at', [now()->startOfWeek(), now()->endOfWeek()]),
                'this_month' => $query->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()]),
                'this_year' => $query->whereBetween('ordered_at', [now()->startOfYear(), now()->endOfYear()]),
                default => null,
            };
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
            'items.menuItem.category',
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
            $order = Order::with(['items.menuItem.category', 'table', 'bill'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (! in_array($order->status, ['pending', 'submitted'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only submitted or pending orders can be confirmed.',
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
                'data' => $order->fresh(['items.menuItem.category', 'bill', 'table', 'waiter', 'creator']),
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
            $openShift = CashShift::where('cashier_id', $request->user()->id)
                ->where('status', 'open')
                ->latest('id')
                ->first();

            if (! $openShift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Open shift is required before creating cashier orders.',
                ], 422);
            }

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

            $validated['payment_type'] = ($validated['payment_type'] ?? 'cash') === 'credit' ? 'credit' : 'cash';

            $isCreditOrder = $validated['payment_type'] === 'credit';

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
            // - tickets are created immediately; bill remains editable until printed
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
                    'payment_type' => $isCreditOrder ? 'credit' : ($order->payment_type ?? 'cash'),
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

    private function recalculateOrderTotals(Order $order): void
    {
        $order->load('items');
        $subtotal = round((float) $order->items->sum(fn ($item) => (float) $item->line_total), 2);
        $tax = round($subtotal * 0.10, 2);
        $serviceCharge = round($subtotal * 0.05, 2);
        $discount = max(0, round((float) ($order->discount ?? 0), 2));
        $total = max(0, round(($subtotal + $tax + $serviceCharge) - $discount, 2));

        $order->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $serviceCharge,
            'total' => $total,
        ]);

        $bill = $order->bill ?: Bill::create([
            'order_id' => $order->id,
            'bill_number' => 'BILL-' . $order->order_number,
            'status' => 'draft',
        ]);

        $bill->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $serviceCharge,
            'discount' => $discount,
            'total' => $total,
            'paid_amount' => 0,
            'balance' => $total,
        ]);
    }

    private function ensureBillNotPrinted(Order $order)
    {
        $order->loadMissing('bill');
        if ($order->bill?->issued_at || $order->bill_printed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Order items cannot be changed after bill is printed.',
            ], 422);
        }

        return null;
    }

    public function addItem(Request $request, $id)
    {
        $order = Order::with(['bill', 'items'])->findOrFail($id);
        $this->authorize('update', $order);

        if ($locked = $this->ensureBillNotPrinted($order)) {
            return $locked;
        }

        $data = $request->validate([
            'menu_item_id' => 'required|integer|exists:menu_items,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($order, $data) {
            $menuItem = MenuItem::lockForUpdate()->findOrFail((int) $data['menu_item_id']);
            if (! $menuItem->is_active || ! $menuItem->is_available) {
                return response()->json(['success' => false, 'message' => 'Menu item is not available.'], 422);
            }

            $quantity = (int) $data['quantity'];
            $unitPrice = round((float) $menuItem->price, 2);
            $lineTotal = round($quantity * $unitPrice, 2);

            $item = OrderItem::create([
                'order_id' => $order->id,
                'menu_item_id' => $menuItem->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'station' => $menuItem->type === 'drink' ? 'bar' : 'kitchen',
                'item_status' => 'confirmed',
                'notes' => $data['notes'] ?? null,
            ]);

            ($item->station === 'bar' ? BarTicket::class : KitchenTicket::class)::create([
                'order_item_id' => $item->id,
                'status' => 'confirmed',
            ]);

            $this->recalculateOrderTotals($order->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Order item added successfully.',
                'data' => $order->fresh(['items.menuItem.category', 'bill', 'table', 'waiter', 'creator']),
            ]);
        });
    }

    public function updateItem(Request $request, $id, $itemId)
    {
        $order = Order::with(['bill', 'items'])->findOrFail($id);
        $this->authorize('update', $order);

        if ($locked = $this->ensureBillNotPrinted($order)) {
            return $locked;
        }

        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($order, $itemId, $data) {
            $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->findOrFail($itemId);
            $quantity = (int) $data['quantity'];
            $item->update([
                'quantity' => $quantity,
                'line_total' => round($quantity * (float) $item->unit_price, 2),
                'notes' => $data['notes'] ?? $item->notes,
            ]);

            $this->recalculateOrderTotals($order->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Order item updated successfully.',
                'data' => $order->fresh(['items.menuItem.category', 'bill', 'table', 'waiter', 'creator']),
            ]);
        });
    }

    public function removeItem($id, $itemId)
    {
        $order = Order::with(['bill', 'items'])->findOrFail($id);
        $this->authorize('update', $order);

        if ($locked = $this->ensureBillNotPrinted($order)) {
            return $locked;
        }

        return DB::transaction(function () use ($order, $itemId) {
            $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->findOrFail($itemId);
            $item->kitchenTicket()?->delete();
            $item->barTicket()?->delete();
            $item->delete();

            $this->recalculateOrderTotals($order->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Order item removed successfully.',
                'data' => $order->fresh(['items.menuItem.category', 'bill', 'table', 'waiter', 'creator']),
            ]);
        });
    }

    public function printBill(Request $request, $id)
    {
        $order = Order::with(['bill', 'items.menuItem.category', 'creditAgreement'])->findOrFail($id);
        $this->authorize('update', $order);

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'customer_tin' => 'nullable|string|max:80',
            'payment_method' => 'nullable|in:cash,card,mobile,transfer',
        ]);

        return DB::transaction(function () use ($request, $order, $data) {
            $order = Order::with(['bill', 'creditAgreement'])->lockForUpdate()->findOrFail($order->id);
            $shift = CashShift::where('cashier_id', $request->user()->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $shift) {
                return response()->json(['success' => false, 'message' => 'Open shift is required before printing a bill.'], 422);
            }

            $bill = $order->bill ?: Bill::create(['order_id' => $order->id, 'bill_number' => 'BILL-' . $order->order_number, 'status' => 'draft']);

            if ($bill->issued_at || $order->bill_printed_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Bill was already printed.',
                    'data' => $order->fresh(['items.menuItem.category', 'bill', 'creditOrder', 'creditAgreement']),
                ]);
            }

            $customerName = trim((string) ($data['customer_name'] ?? $order->customer_name ?? 'Guest')) ?: 'Guest';
            $customerTin = trim((string) ($data['customer_tin'] ?? $order->customer_tin ?? '')) ?: null;

            $order->update([
                'customer_name' => $customerName,
                'customer_tin' => $customerTin,
                'bill_printed_at' => now(),
            ]);

            $bill->update([
                'customer_name' => $customerName,
                'customer_tin' => $customerTin,
                'issued_by' => $request->user()->id,
                'issued_at' => now(),
                'cash_shift_id' => $shift->id,
            ]);

            if ($order->payment_type === 'credit') {
                if (! $order->credit_agreement_id) {
                    return response()->json(['success' => false, 'message' => 'Credit agreement is required before printing a credit bill.'], 422);
                }

                if (! $order->creditOrder) {
                    $this->creditOrderService->createForBill(
                        $bill->fresh(),
                        (int) $order->credit_account_id,
                        (int) $request->user()->id,
                        null,
                        $order->notes,
                        false,
                        null,
                        false,
                        (int) $order->credit_agreement_id
                    );
                }

                $bill->update([
                    'status' => 'credit',
                    'bill_type' => 'credit',
                    'payment_method' => 'credit',
                    'credit_status' => 'credit_pending',
                    'cash_shift_id' => $shift->id,
                ]);
                $order->update(['credit_status' => 'credit_pending']);
            } else {
                $method = $data['payment_method'] ?? 'cash';
                Payment::create([
                    'bill_id' => $bill->id,
                    'method' => $method,
                    'amount' => round((float) $bill->total, 2),
                    'reference' => 'AUTO-' . $order->order_number,
                    'status' => 'paid',
                    'received_by' => $request->user()->id,
                    'cash_shift_id' => $shift->id,
                    'paid_at' => now(),
                ]);

                $bill->update([
                    'paid_amount' => $bill->total,
                    'balance' => 0,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'bill_type' => 'normal',
                    'payment_method' => $method,
                    'cash_shift_id' => $shift->id,
                ]);
                $order->update(['payment_type' => $method, 'status' => 'completed', 'completed_at' => now()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Bill printed and financial record created successfully.',
                'data' => $order->fresh(['items.menuItem.category', 'bill.payments', 'creditOrder.account', 'creditAgreement', 'table', 'waiter', 'creator']),
            ]);
        });
    }


    /**
     * Receive payment directly against the order.
     * The order remains the source of truth; this method does not create a bill.
     * POST /cashier/orders/{id}/receive-payment
     */
    public function receivePayment(Request $request, $id)
    {
        $order = Order::with(['items.menuItem.category', 'table', 'waiter', 'creator', 'bill'])
            ->findOrFail($id);
        $this->authorize('update', $order);

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:120',
            'customer_tin' => 'nullable|string|max:80',
            'payment_method' => 'nullable|in:cash,card,mobile,transfer,credit',
            'paid_amount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $order, $data) {
            $order = Order::with(['items.menuItem.category', 'table', 'waiter', 'creator', 'bill'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (in_array((string) $order->status, ['cancelled', 'void'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cancelled or voided orders cannot receive payment.',
                ], 422);
            }

            if ((string) $order->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only confirmed orders can receive payment. Please confirm submitted waiter orders first.',
                ], 422);
            }

            if ((string) ($order->payment_status ?? 'unpaid') === 'paid' || $order->paid_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order payment was already received.',
                    'data' => $order->fresh(['items.menuItem.category', 'table', 'waiter', 'creator', 'bill']),
                ]);
            }

            $shift = CashShift::where('cashier_id', $request->user()->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $shift) {
                return response()->json([
                    'success' => false,
                    'message' => 'Open shift is required before receiving order payment.',
                ], 422);
            }

            $total = round((float) ($order->total ?? 0), 2);
            $paidAmount = array_key_exists('paid_amount', $data)
                ? round((float) $data['paid_amount'], 2)
                : $total;

            if ($paidAmount < $total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paid amount cannot be less than the order total.',
                    'errors' => [
                        'paid_amount' => ['Paid amount cannot be less than the order total.'],
                    ],
                ], 422);
            }

            $isCreditOrder = (string) $order->payment_type === 'credit';
            $method = $isCreditOrder ? 'credit' : ($data['payment_method'] ?? $order->payment_method ?? 'cash');

            if ($method === 'credit' && ! $isCreditOrder) {
                $method = 'cash';
            }

            $customerName = trim((string) ($data['customer_name'] ?? $order->customer_name ?? 'Guest')) ?: 'Guest';
            $customerTin = trim((string) ($data['customer_tin'] ?? $order->customer_tin ?? '')) ?: null;
            $changeAmount = max(0, round($paidAmount - $total, 2));

            $order->update([
                'customer_name' => $customerName,
                'customer_tin' => $customerTin,
                'payment_method' => $method,
                'payment_status' => 'paid',
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'paid_at' => now(),
                'payment_received_by' => $request->user()->id,
                'status' => 'completed',
                'completed_at' => now(),
                'credit_status' => $isCreditOrder ? 'paid' : $order->credit_status,
            ]);

            // Keep legacy bill data synchronized only when a bill already exists.
            // The order remains the source of truth.
            if ($order->bill) {
                $order->bill->update([
                    'customer_name' => $customerName,
                    'customer_tin' => $customerTin,
                    'paid_amount' => $total,
                    'balance' => 0,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => $method,
                    'cash_shift_id' => $shift->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $isCreditOrder
                    ? 'Credit order payment received successfully.'
                    : 'Cash order payment received successfully.',
                'data' => $order->fresh(['items.menuItem.category', 'table', 'waiter', 'creator', 'bill']),
            ]);
        });
    }

}