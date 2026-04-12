<?php

namespace App\Http\Controllers\Api;

use \Log;
use App\Http\Controllers\Controller;
use App\Models\BarTicket;
use App\Models\Bill;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\InventoryDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        private InventoryDeductionService $inventoryDeductionService
    ) {
    }

    /**
     * List orders with filters (for staff)
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $query = Order::with(['creator', 'waiter', 'table', 'items.menuItem', 'bill.payments']);

        // Filter by order type
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->whereDate('ordered_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('ordered_at', '<=', $request->to_date);
        }

        // Filter by table
        if ($request->filled('table_id')) {
            $query->where('table_id', $request->table_id);
        }

        // Filter by waiter
        if ($request->filled('waiter_id')) {
            $query->where('waiter_id', $request->waiter_id);
        }

        // Search by order number or customer
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $orders = $query->orderBy('id', 'desc')->paginate($perPage);

        // Transform orders to include full receipt URLs
        $orders->getCollection()->transform(function ($order) {
            if ($order->bill && $order->bill->payments) {
                $order->bill->payments->transform(function ($payment) {
                    if ($payment->receipt_path) {
                        $payment->receipt_url = url('storage/' . $payment->receipt_path);
                    }
                    return $payment;
                });
            }
            return $order;
        });

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get single order details
     */
   public function show($id)
   {
   $order = Order::with([
   'creator',
   'waiter',
   'table',
   'items.menuItem',
   'items.kitchenTicket',
   'items.barTicket',
   'bill.payments.receivedBy'
   ])->findOrFail($id);

   // Transform payments to include full receipt URL and debug info
   if ($order->bill && $order->bill->payments) {
   $order->bill->payments->transform(function ($payment) {
   if ($payment->receipt_path) {
   // Log for debugging
   \Log::info('Receipt path from DB: ' . $payment->receipt_path);

   // Construct URL
   $payment->receipt_url = url('storage/' . $payment->receipt_path);

   // Add debug info
   $payment->receipt_debug = [
   'path' => $payment->receipt_path,
   'full_url' => $payment->receipt_url,
   'file_exists' => file_exists(storage_path('app/public/' . $payment->receipt_path)),
   'storage_path' => storage_path('app/public/' . $payment->receipt_path)
   ];

   Log::info('Receipt URL: ' . $payment->receipt_url);
   \Log::info('File exists: ' . (file_exists(storage_path('app/public/' . $payment->receipt_path)) ? 'Yes' : 'No'));
   }
   return $payment;
   });
   }

   return response()->json([
   'success' => true,
   'data' => $order
   ]);
   }

    /**
     * Create new order (for staff: waiter, cashier)
     */
    public function store(Request $request)
    {
        $request->validate([
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'table_id' => 'required_if:order_type,dine_in|exists:dining_tables,id',
            'customer_name' => 'required_if:order_type,delivery|string|nullable',
            'customer_phone' => 'required_if:order_type,delivery|string|nullable',
            'customer_address' => 'required_if:order_type,delivery|string|nullable',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'waiter_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            // Generate order number
            $orderNumber = $this->generateOrderNumber();

            // Calculate totals
            $subtotal = 0;
            $items = [];

            foreach ($request->items as $item) {
                $menuItem = \App\Models\MenuItem::find($item['menu_item_id']);
                
                // Check if item is active and available
                if (!$menuItem->is_active || !$menuItem->is_available) {
                    throw new \Exception("Item {$menuItem->name} is not available");
                }

                $itemSubtotal = $menuItem->price * $item['quantity'];
                $subtotal += $itemSubtotal;

                $items[] = [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menuItem->price,
                    'line_total' => $itemSubtotal,
                    'station' => $menuItem->type === 'food' ? 'kitchen' : 'bar',
                    'item_status' => 'pending',
                    'notes' => $item['notes'] ?? null,
                    'modifiers' => $item['modifiers'] ?? null,
                ];
            }

            // Calculate tax and service charge
            $taxRate = 0.10; // 10%
            $serviceChargeRate = 0.05; // 5%
            
            $tax = $subtotal * $taxRate;
            $serviceCharge = $subtotal * $serviceChargeRate;
            $total = $subtotal + $tax + $serviceCharge;

            // Create order
            $order = Order::create([
                'order_number' => $orderNumber,
                'order_type' => $request->order_type,
                'table_id' => $request->table_id,
                'created_by' => auth()->id(),
                'waiter_id' => $request->waiter_id ?? auth()->id(),
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_address' => $request->customer_address,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'total' => $total,
                'notes' => $request->notes,
                'ordered_at' => now()
            ]);

            // Create order items and tickets
            foreach ($items as $itemData) {
                $itemData['order_id'] = $order->id;
                $orderItem = OrderItem::create($itemData);

                // Create station ticket
                if ($orderItem->station === 'kitchen') {
                    KitchenTicket::create([
                        'order_item_id' => $orderItem->id,
                        'status' => 'pending'
                    ]);
                } else {
                    BarTicket::create([
                        'order_item_id' => $orderItem->id,
                        'status' => 'pending'
                    ]);
                }
            }

            // Create bill
            $bill = Bill::create([
                'order_id' => $order->id,
                'bill_number' => 'BILL-' . $orderNumber,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'discount' => 0,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'status' => 'issued',
                'issued_at' => now()
            ]);

            // If table is dine-in, update table status
            if ($request->order_type === 'dine_in' && $request->table_id) {
                $table = \App\Models\DiningTable::find($request->table_id);
                $table->update(['status' => 'occupied']);
            }

            DB::commit();

            // Load relationships
            $order->load(['items.menuItem', 'creator', 'waiter', 'table', 'bill']);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'table_id' => 'nullable|exists:dining_tables,id',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'customer_address' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'waiter_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($id);

            if (!in_array($order->status, ['pending'], true)) {
                return response()->json(['success' => false, 'message' => 'Only pending orders can be edited'], 422);
            }

            if ($order->table_id && $order->order_type === 'dine_in' && (int) $request->table_id !== (int) $order->table_id) {
                optional($order->table)->update(['status' => 'available']);
            }

            $subtotal = 0;
            $items = [];
            foreach ($request->items as $item) {
                $menuItem = \App\Models\MenuItem::findOrFail($item['menu_item_id']);
                if (!$menuItem->is_active || !$menuItem->is_available) {
                    return response()->json(['success' => false, 'message' => 'Item not available: ' . $menuItem->name], 422);
                }
                $itemSubtotal = $menuItem->price * $item['quantity'];
                $subtotal += $itemSubtotal;
                $items[] = [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $menuItem->price,
                    'line_total' => $itemSubtotal,
                    'station' => $menuItem->type === 'food' ? 'kitchen' : 'bar',
                    'item_status' => 'pending',
                    'notes' => $item['notes'] ?? null,
                    'modifiers' => $item['modifiers'] ?? null,
                ];
            }

            $tax = $subtotal * 0.10;
            $serviceCharge = $subtotal * 0.05;
            $total = $subtotal + $tax + $serviceCharge;

            $order->update([
                'order_type' => $request->order_type,
                'table_id' => $request->table_id,
                'waiter_id' => $request->waiter_id ?? $order->waiter_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_address' => $request->customer_address,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'total' => $total,
                'notes' => $request->notes,
            ]);

            foreach ($order->items as $existing) {
                $existing->kitchenTicket()?->delete();
                $existing->barTicket()?->delete();
                $existing->delete();
            }

            foreach ($items as $itemData) {
                $itemData['order_id'] = $order->id;
                $orderItem = OrderItem::create($itemData);
                if ($orderItem->station === 'kitchen') {
                    KitchenTicket::create(['order_item_id' => $orderItem->id, 'status' => 'pending']);
                } else {
                    BarTicket::create(['order_item_id' => $orderItem->id, 'status' => 'pending']);
                }
            }

            if ($request->order_type === 'dine_in' && $request->table_id) {
                \App\Models\DiningTable::where('id', $request->table_id)->update(['status' => 'occupied']);
            }

            $bill = Bill::where('order_id', $order->id)->lockForUpdate()->first();
            if ($bill && in_array($bill->status, ['draft', 'issued', 'partial'], true)) {
                $bill->subtotal = $subtotal;
                $bill->tax = $tax;
                $bill->service_charge = $serviceCharge;
                $bill->total = $total;
                $bill->balance = max(0, $total - (float) $bill->paid_amount);
                $bill->status = (float) $bill->balance <= 0 ? 'paid' : ((float) $bill->paid_amount > 0 ? 'partial' : $bill->status);
                $bill->save();
            }

            return response()->json(['success' => true, 'data' => $order->fresh()->load(['items.menuItem', 'creator', 'waiter', 'table', 'bill'])]);
        });
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,confirmed,in_progress,ready,served,completed,cancelled'
        ]);

        $order = Order::findOrFail($id);
        
        $oldStatus = $order->status;
        $order->status = $request->status;

        // Set timestamps based on status
        if ($request->status === 'confirmed' && !$order->confirmed_at) {
            $order->confirmed_at = now();
        } elseif ($request->status === 'completed' && !$order->completed_at) {
            $order->completed_at = now();
            
            // If completed, free up the table
            if ($order->table_id) {
                $order->table->update(['status' => 'available']);
            }
        } elseif ($request->status === 'cancelled') {
            // If cancelled, free up the table
            if ($order->table_id) {
                $order->table->update(['status' => 'available']);
            }
        }

        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Order status updated',
            'data' => $order
        ]);
    }

    /**
     * Update order item status (for kitchen/bar)
     */
    public function updateItemStatus(Request $request, $itemId)
    {
        $request->validate([
            'status' => 'required|in:pending,preparing,ready,served,cancelled,delayed,rejected',
            'station' => 'required|in:kitchen,bar',
            'estimated_minutes' => 'nullable|integer|min:1',
            'delay_reason' => 'required_if:status,delayed|nullable|string',
            'rejection_reason' => 'required_if:status,rejected|nullable|string',
            'prep_note' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $orderItem = OrderItem::findOrFail($itemId);
            
            // Verify station matches
            if ($orderItem->station !== $request->station) {
                throw new \Exception("This item belongs to {$orderItem->station}, not {$request->station}");
            }

            $oldStatus = $orderItem->item_status;
            $orderItem->item_status = $request->status;

            // Set timestamps
            if ($request->status === 'preparing' && !$orderItem->started_at) {
                $orderItem->started_at = now();
            } elseif ($request->status === 'ready' && !$orderItem->ready_at) {
                $orderItem->ready_at = now();
            } elseif ($request->status === 'served' && !$orderItem->served_at) {
                $orderItem->served_at = now();
            }

            $orderItem->save();

            if ($request->status === 'confirmed') {
                $this->inventoryDeductionService->deductForOrderItem($orderItem->fresh(), (int) auth()->id());
            }

            // Update corresponding ticket
            if ($request->station === 'kitchen') {
                $ticket = KitchenTicket::where('order_item_id', $itemId)->first();
                if ($ticket) {
                    $ticket->status = $request->status;
                    $ticket->estimated_minutes = $request->estimated_minutes;
                    $ticket->delay_reason = $request->delay_reason;
                    $ticket->rejection_reason = $request->rejection_reason;
                    $ticket->prep_note = $request->prep_note;
                    
                    if ($request->status === 'preparing' && !$ticket->started_at) {
                        $ticket->started_at = now();
                    } elseif (in_array($request->status, ['ready', 'served']) && !$ticket->completed_at) {
                        $ticket->completed_at = now();
                        $ticket->chef_id = auth()->id();
                    }
                    
                    $ticket->save();
                }
            } else {
                $ticket = BarTicket::where('order_item_id', $itemId)->first();
                if ($ticket) {
                    $ticket->status = $request->status;
                    $ticket->estimated_minutes = $request->estimated_minutes;
                    $ticket->delay_reason = $request->delay_reason;
                    $ticket->rejection_reason = $request->rejection_reason;
                    $ticket->prep_note = $request->prep_note;
                    
                    if ($request->status === 'preparing' && !$ticket->started_at) {
                        $ticket->started_at = now();
                    } elseif (in_array($request->status, ['ready', 'served']) && !$ticket->completed_at) {
                        $ticket->completed_at = now();
                        $ticket->barman_id = auth()->id();
                    }
                    
                    $ticket->save();
                }
            }

            // Check if all items are ready to update order status
            $order = Order::find($orderItem->order_id);
            $allItems = $order->items;
            
            if ($allItems->every(fn($item) => $item->item_status === 'served')) {
                $order->status = 'served';
                $order->save();
            } elseif ($allItems->every(fn($item) => in_array($item->item_status, ['ready', 'served']))) {
                $order->status = 'ready';
                $order->save();
            } elseif ($allItems->contains(fn($item) => $item->item_status === 'preparing')) {
                $order->status = 'in_progress';
                $order->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item status updated',
                'data' => $orderItem
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get orders for kitchen display
     */
    public function kitchenOrders(Request $request)
    {
        $orders = Order::with(['items' => function($q) {
            $q->where('station', 'kitchen')
              ->whereNotIn('item_status', ['served', 'cancelled', 'rejected'])
              ->with('kitchenTicket');
        }, 'table'])
        ->whereIn('status', ['confirmed', 'in_progress', 'ready'])
        ->orderBy('ordered_at', 'asc')
        ->get()
        ->filter(fn($order) => $order->items->isNotEmpty());

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get orders for bar display
     */
    public function barOrders(Request $request)
    {
        $orders = Order::with(['items' => function($q) {
            $q->where('station', 'bar')
              ->whereNotIn('item_status', ['served', 'cancelled', 'rejected'])
              ->with('barTicket');
        }, 'table'])
        ->whereIn('status', ['confirmed', 'in_progress', 'ready'])
        ->orderBy('ordered_at', 'asc')
        ->get()
        ->filter(fn($order) => $order->items->isNotEmpty());

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber()
    {
        $date = now()->format('Ymd');
        $lastOrder = Order::whereDate('created_at', today())
                         ->orderBy('id', 'desc')
                         ->first();

        if ($lastOrder) {
            $lastNumber = intval(substr($lastOrder->order_number, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "ORD-{$date}-{$newNumber}";
    }

    public function confirm($id)
    {
        $order = Order::with('items')->findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be confirmed'
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'confirmed',
            ]);

            foreach ($order->items as $item) {
                if ($item->station === 'kitchen') {
                    KitchenTicket::firstOrCreate(
                        ['order_item_id' => $item->id],
                        [
                            'status' => 'pending',
                            'delay_reason' => null,
                            'rejection_reason' => null,
                            'started_at' => null,
                            'completed_at' => null,
                        ]
                    );
                }

                if ($item->station === 'bar') {
                    BarTicket::firstOrCreate(
                        ['order_item_id' => $item->id],
                        [
                            'status' => 'pending',
                            'delay_reason' => null,
                            'rejection_reason' => null,
                            'started_at' => null,
                            'completed_at' => null,
                        ]
                    );
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Order confirmed and tickets created',
        ]);
    }

    public function cancel($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if (in_array($order->status, ['completed','cancelled'], true)) {
                return response()->json(['success' => false, 'message' => 'Cannot cancel completed/cancelled order'], 422);
            }

            $this->inventoryDeductionService->restoreForOrder($order, auth()->id());

            $order->status = 'cancelled';
            $order->save();

            OrderItem::where('order_id', $order->id)->update(['item_status' => 'cancelled']);

            return response()->json(['success' => true, 'data' => $order->fresh(['items.menuItem', 'bill'])]);
        });
    }

    public function dispatch(Request $request, $id)
    {
        $data = $request->validate([
            'rider_id' => 'required|exists:users,id',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if ($order->order_type !== 'delivery') {
                return response()->json(['success' => false, 'message' => 'Not a delivery order'], 422);
            }

            if (!in_array($order->status, ['ready','confirmed','in_progress'], true)) {
                return response()->json(['success' => false, 'message' => 'Order not ready for dispatch'], 422);
            }

            $order->update([
                'rider_id' => $data['rider_id'],
                'status' => 'out_for_delivery',
                'dispatched_at' => now(),
            ]);

            return response()->json(['success' => true, 'data' => $order]);
        });
    }

    public function markDelivered($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if ($order->order_type !== 'delivery') {
                return response()->json(['success' => false, 'message' => 'Not a delivery order'], 422);
            }

            if ($order->status !== 'out_for_delivery') {
                return response()->json(['success' => false, 'message' => 'Order not out for delivery'], 422);
            }

            $order->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            OrderItem::where('order_id', $order->id)
                ->whereNotIn('item_status', ['cancelled','rejected'])
                ->update(['item_status' => 'served', 'served_at' => now()]);

            return response()->json(['success' => true, 'data' => $order]);
        });
    }

    public function markServed($id)
    {
        return DB::transaction(function () use ($id) {
            $order = Order::lockForUpdate()->findOrFail($id);

            if (!in_array($order->order_type, ['dine_in','takeaway'], true)) {
                return response()->json(['success' => false, 'message' => 'Not dine-in/takeaway'], 422);
            }

            if (!in_array($order->status, ['ready','confirmed','in_progress'], true)) {
                return response()->json(['success' => false, 'message' => 'Order not ready to serve'], 422);
            }

            $order->update([
                'status' => 'served',
                'served_at' => now(),
            ]);

            OrderItem::where('order_id', $order->id)
                ->whereNotIn('item_status', ['cancelled','rejected'])
                ->update(['item_status' => 'served', 'served_at' => now()]);

            return response()->json(['success' => true, 'data' => $order]);
        });
    }

    public function complete(InventoryDeductionService $svc, $id)
    {
        return DB::transaction(function () use ($svc, $id) {
            $order = Order::with(['items','bill'])->lockForUpdate()->findOrFail($id);

            if ($order->status === 'completed') {
                return response()->json(['success' => true, 'data' => $order]);
            }

            if (!$order->bill) {
                return response()->json(['success' => false, 'message' => 'Bill not found'], 422);
            }

            if (!in_array($order->bill->status, ['paid'], true) || (float)$order->bill->balance > 0) {
                return response()->json(['success' => false, 'message' => 'Bill must be fully paid'], 422);
            }

            if ($order->order_type === 'delivery' && $order->status !== 'delivered') {
                return response()->json(['success' => false, 'message' => 'Delivery order must be DELIVERED'], 422);
            }

            if (in_array($order->order_type, ['dine_in','takeaway'], true) && $order->status !== 'served') {
                return response()->json(['success' => false, 'message' => 'Order must be SERVED'], 422);
            }

            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return response()->json(['success' => true, 'data' => $order->fresh()->load('items','bill')]);
        });
    }

    protected function refreshOrderStatusFromTickets(Order $order): void
    {
        $order->load([
            'items.kitchenTicket',
            'items.barTicket',
        ]);

        $tickets = collect();

        foreach ($order->items as $item) {
            if ($item->station === 'kitchen' && $item->kitchenTicket) {
                $tickets->push($item->kitchenTicket);
            }

            if ($item->station === 'bar' && $item->barTicket) {
                $tickets->push($item->barTicket);
            }
        }

        if ($tickets->isEmpty()) {
            return;
        }

        $allReady = $tickets->every(fn ($t) => $t->status === 'ready');
        $anyPreparing = $tickets->contains(fn ($t) => in_array($t->status, ['preparing', 'delayed'], true));
        $anyRejected = $tickets->contains(fn ($t) => $t->status === 'rejected');

        if ($allReady) {
            $order->update(['status' => 'ready']);
            return;
        }

        if ($anyRejected) {
            $order->update(['status' => 'issue']);
            return;
        }

        if ($anyPreparing) {
            $order->update(['status' => 'in_progress']);
            return;
        }
    }
}