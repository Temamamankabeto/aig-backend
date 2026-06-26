<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Models\BarTicket;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WaiterOrderService;
use App\Services\NotificationService;
use App\Services\AuditLogger;
use App\Services\InventoryDeductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WaiterOrderController extends Controller
{
    public function __construct(
        private WaiterOrderService $waiterOrderService,
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
        private InventoryDeductionService $inventoryDeductionService,
    ) {
    }
    /**
     * Menu list for waiter order page
     */
    public function menu(Request $request)
    {
        $this->authorize('create', Order::class);
    
        $query = MenuItem::query()
            ->with(['category'])
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
                    'image_path' => $item->image_path,
                    'image_url' => $item->image_path
                        ? asset('storage/' . $item->image_path)
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

    public function myOrders(Request $request)
    {
        $this->authorize('create', Order::class);
    
        $userId = auth()->id();
    
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ], 401);
        }
    
        $perPage = max(1, (int) $request->get('per_page', 10));
    
        $query = Order::with(['table', 'items.menuItem', 'bill'])
            ->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhere('waiter_id', $userId);
            });
    
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
    
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }
    
        if (
            $request->filled('order_type') &&
            in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
        ) {
            $query->where('order_type', $request->order_type);
        }
    
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->whereHas('bill', function ($bill) use ($request) {
                $bill->where('status', $request->payment_status);
            });
        }

        $period = $request->get('period');
        if ($period === 'today') {
            $query->whereDate('ordered_at', now()->toDateString());
        } elseif ($period === 'this_week') {
            $query->whereBetween('ordered_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'this_month') {
            $query->whereYear('ordered_at', now()->year)->whereMonth('ordered_at', now()->month);
        } elseif ($period === 'this_year') {
            $query->whereYear('ordered_at', now()->year);
        } elseif ($period === 'custom') {
            if ($request->filled('date_from')) {
                $query->whereDate('ordered_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('ordered_at', '<=', $request->date_to);
            }
        }

        $reportQuery = clone $query;

        $orders = $query
            ->orderByDesc('ordered_at')
            ->paginate($perPage);
    
        $orders->getCollection()->transform(function ($order) {
            $order->item_count = $order->items?->count() ?? 0;
            return $order;
        });
    
        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
                'report' => [
                    'total_orders' => (clone $reportQuery)->count(),
                    'total_cost' => (float) (clone $reportQuery)->sum('total'),
                ],
            ],
        ]);
    }
    /**
     * Tables list for waiter order page
     */
    public function tables(Request $request)
    {
        $this->authorize('create', Order::class);
    
        $query = DiningTable::query()
            ->where('is_active', true);
    
        if ($request->boolean('available_only', false)) {
            $query->where('status', 'available');
        }
    
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
    
            $query->where(function ($q) use ($search) {
                $q->where('table_number', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhere('section', 'like', "%{$search}%");
            });
        }
    
        if (
            $request->filled('status') &&
            in_array($request->status, ['available', 'occupied', 'reserved', 'cleaning'], true)
        ) {
            $query->where('status', $request->status);
        }
    
        if ($request->filled('section')) {
            $section = trim((string) $request->section);
            $query->where('section', 'like', "%{$section}%");
        }
    
        $tables = $query
            ->orderByRaw("
                CASE
                    WHEN status = 'available' THEN 1
                    WHEN status = 'reserved' THEN 2
                    WHEN status = 'occupied' THEN 3
                    ELSE 4
                END
            ")
            ->orderBy('table_number')
            ->get()
            ->map(function ($table) {
                return [
                    'id' => $table->id,
                    'table_number' => (string) $table->table_number,
                    'name' => $table->name ?: ('Table ' . $table->table_number),
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
            'message' => 'Tables fetched successfully',
            'data' => $tables,
        ]);
    }

    /**
     * Store waiter order
  /**
 * Store waiter order
 */
public function store(StoreOrderRequest $request)
{
    $this->authorize('create', Order::class);

    try {
        $validated = $request->validated();
        $userId = (int) auth()->id();

        $orderType = $validated['order_type'] ?? null;

        if (!in_array($orderType, ['dine_in', 'takeaway'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Waiter can create dine-in and takeaway orders only.',
                'errors' => [
                    'order_type' => ['Waiter can create dine-in and takeaway orders only.'],
                ],
            ], 422);
        }

        // Payment type is intentionally not set at order creation.
        // Cashier records cash/card/mobile/transfer/credit later during payment.
        unset($validated['payment_type'], $validated['credit_account_id'], $validated['credit_due_date'], $validated['credit_notes'], $validated['override_credit_limit']);

        $validated['waiter_id'] = $userId;
        $validated['created_by'] = $userId;
        $validated['_source'] = 'waiter';

        $validated['customer_name'] = isset($validated['customer_name'])
            ? (trim((string) $validated['customer_name']) ?: 'Guest')
            : 'Guest';

        $validated['customer_phone'] = isset($validated['customer_phone'])
            ? (trim((string) $validated['customer_phone']) ?: null)
            : null;

        $validated['customer_address'] = isset($validated['customer_address'])
            ? (trim((string) $validated['customer_address']) ?: null)
            : null;

        $validated['notes'] = isset($validated['notes'])
            ? (trim((string) $validated['notes']) ?: null)
            : null;

        $validated['discount'] = isset($validated['discount'])
            ? (float) $validated['discount']
            : 0;

        if ($orderType !== 'dine_in') {
            $validated['table_id'] = null;
        }

        if ($orderType === 'delivery' && empty($validated['customer_address'])) {
            return response()->json([
                'success' => false,
                'message' => 'Customer address is required for delivery orders.',
                'errors' => [
                    'customer_address' => ['Customer address is required for delivery orders.'],
                ],
            ], 422);
        }

        if ($orderType === 'dine_in' && empty($validated['table_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Table is required for dine-in orders.',
                'errors' => [
                    'table_id' => ['Table is required for dine-in orders.'],
                ],
            ], 422);
        }

        if ($orderType === 'takeaway') {
            $validated['customer_address'] = null;
        }

        $validated['items'] = collect($validated['items'] ?? [])
            ->map(function ($item) {
                $itemNote = $item['notes'] ?? $item['note'] ?? null;

                return [
                    'menu_item_id' => (int) ($item['menu_item_id'] ?? 0),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'notes' => is_string($itemNote) ? (trim($itemNote) ?: null) : null,
                    'modifiers' => isset($item['modifiers']) && is_array($item['modifiers'])
                        ? $item['modifiers']
                        : [],
                ];
            })
            ->filter(fn ($item) => $item['menu_item_id'] > 0 && $item['quantity'] > 0)
            ->values()
            ->all();

        if (empty($validated['items'])) {
            return response()->json([
                'success' => false,
                'message' => 'At least one valid order item is required.',
                'errors' => [
                    'items' => ['At least one valid order item is required.'],
                ],
            ], 422);
        }

        $order = $this->waiterOrderService->createOrder($validated, $userId);

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully.',
            'data' => $order,
        ], 201);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage() ?: 'Failed to create order.',
        ], 422);
    }
}

   

      public function requestCancel(Request $request, $id)
 {
   $order = Order::findOrFail($id);
   $this->authorize('requestCancel', $order);
   $data = $request->validate([
   'reason' => 'nullable|string|max:1000',
   ]);

   return DB::transaction(function () use ($id, $data) {
   $order = Order::with(['bill', 'items', 'table'])
   ->lockForUpdate()
   ->findOrFail($id);

   if (!in_array($order->status, ['confirmed', 'in_progress', 'ready'], true)) {
   return response()->json([
   'success' => false,
   'message' => 'Only confirmed, in-progress, or ready orders can request cancellation.',
   ], 422);
   }

   if (!$order->ordered_at) {
   return response()->json([
   'success' => false,
   'message' => 'Order time is missing.',
   ], 422);
   }

   $minutesSinceOrder = now()->diffInMinutes($order->ordered_at);

   if ($minutesSinceOrder > 10) {
   return response()->json([
   'success' => false,
   'message' => 'Cancellation request is only allowed within 10 minutes of order creation.',
   ], 422);
   }

   if ($order->status === 'cancel_requested') {
   return response()->json([
   'success' => false,
   'message' => 'Cancellation has already been requested for this order.',
   ], 422);
   }

   $order->update([
   'status' => 'cancel_requested',
   'cancel_requested_at' => now(),
   'cancel_requested_by' => auth()->id(),
   'cancel_request_reason' => $data['reason'] ?? null,
   ]);

   return response()->json([
   'success' => true,
   'message' => 'Cancellation requested successfully.',
   'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
   ]);
   });
   }

   public function approveCancel(Request $request, $id)
   {
   $order = Order::findOrFail($id);
   $this->authorize('update', $order);
   $data = $request->validate([
   'reason' => 'nullable|string|max:1000',
   ]);

   return DB::transaction(function () use ($id, $data) {
   $order = Order::with(['bill', 'items', 'table'])
   ->lockForUpdate()
   ->findOrFail($id);

   if ($order->status !== 'cancel_requested') {
   return response()->json([
   'success' => false,
   'message' => 'Only cancel-requested orders can be approved.',
   ], 422);
   }

   $reason = $data['reason'] ?? $order->cancel_request_reason ?? 'Order cancellation approved';

   $order->update([
   'status' => 'cancelled',
   'voided_at' => now(),
   'voided_by' => auth()->id(),
   'void_reason' => $reason,
   ]);

   OrderItem::where('order_id', $order->id)
   ->whereNotIn('item_status', ['served', 'cancelled'])
   ->update([
   'item_status' => 'cancelled',
   ]);

   KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id);
   })
   ->whereNotIn('status', ['ready'])
   ->update([
   'status' => 'rejected',
   'rejection_reason' => $reason,
   ]);

   BarTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id);
   })
   ->whereNotIn('status', ['ready'])
   ->update([
   'status' => 'rejected',
   'rejection_reason' => $reason,
   ]);

   if ($order->bill && in_array($order->bill->status, ['draft', 'issued', 'partial'], true)) {
   $order->bill->update([
   'status' => 'void',
   'balance' => 0,
   ]);
   }

   if ($order->order_type === 'dine_in' && $order->table_id) {
   optional($order->table)->update([
   'status' => 'available',
   ]);
   }

   return response()->json([
   'success' => true,
   'message' => 'Order cancellation approved successfully.',
   'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
   ]);
   });
   }

   public function voidOrder(Request $request, $id)
   {
   $order = Order::findOrFail($id);
   $this->authorize('update', $order);
   $data = $request->validate([
   'reason' => 'required|string|max:1000',
   ]);

   return DB::transaction(function () use ($id, $data) {
   $order = Order::with(['bill', 'items', 'table'])
   ->lockForUpdate()
   ->findOrFail($id);

   if (in_array($order->status, ['completed', 'cancelled', 'void'], true)) {
   return response()->json([
   'success' => false,
   'message' => 'This order cannot be voided.',
   ], 422);
   }

   $reason = $data['reason'];

   $order->update([
   'status' => 'void',
   'voided_at' => now(),
   'voided_by' => auth()->id(),
   'void_reason' => $reason,
   ]);

   OrderItem::where('order_id', $order->id)
   ->whereNotIn('item_status', ['served', 'cancelled'])
   ->update([
   'item_status' => 'cancelled',
   ]);

   KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id);
   })
   ->whereNotIn('status', ['ready'])
   ->update([
   'status' => 'rejected',
   'rejection_reason' => $reason,
   ]);

   BarTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id);
   })
   ->whereNotIn('status', ['ready'])
   ->update([
   'status' => 'rejected',
   'rejection_reason' => $reason,
   ]);

   if ($order->bill) {
   $order->bill->update([
   'status' => 'void',
   'balance' => 0,
   ]);
   }

   if ($order->order_type === 'dine_in' && $order->table_id) {
   optional($order->table)->update([
   'status' => 'available',
   ]);
   }

   return response()->json([
   'success' => true,
   'message' => 'Order voided successfully.',
   'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
   ]);
   });
   }

public function cancelableOrders(Request $request)
 {
 $waiterId = auth()->id();

 $query = Order::with(['table', 'items.menuItem', 'bill'])
 ->where('waiter_id', $waiterId)
 ->whereIn('status', ['confirmed', 'in_progress', 'ready'])
 ->where('ordered_at', '>=', now()->subMinutes(10));

 if ($request->filled('search')) {
 $search = trim($request->search);

 $query->where(function ($q) use ($search) {
 $q->where('order_number', 'like', "%{$search}%")
 ->orWhere('customer_name', 'like', "%{$search}%")
 ->orWhere('customer_phone', 'like', "%{$search}%");
 });
 }

 $orders = $query
 ->orderByDesc('ordered_at')
 ->get()
 ->map(function ($order) {
 $remaining = max(
 0,
 now()->diffInSeconds(
 \Carbon\Carbon::parse($order->ordered_at)->addMinutes(10),
 false
 )
 );

 $order->item_count = $order->items?->count() ?? 0;
 $order->cancel_window_remaining_seconds = $remaining;
 $order->can_request_cancel = $remaining > 0;

 return $order;
 })
 ->values();

 return response()->json([
 'success' => true,
 'data' => $orders,
 ]);
 }

 public function confirmedOrders(Request $request)
{
    $waiterId = auth()->id();
    $perPage = max(1, (int) $request->get('per_page', 10));

    $query = Order::with(['table', 'items.menuItem', 'bill'])
        ->where('waiter_id', $waiterId)
        ->where('status', 'confirmed');

    if ($request->filled('search')) {
        $search = trim((string) $request->search);

        $query->where(function ($q) use ($search) {
            $q->where('order_number', 'like', "%{$search}%")
              ->orWhere('customer_name', 'like', "%{$search}%")
              ->orWhere('customer_phone', 'like', "%{$search}%");
        });
    }

    if (
        $request->filled('order_type') &&
        in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
    ) {
        $query->where('order_type', $request->order_type);
    }

    $orders = $query
        ->orderByDesc('ordered_at')
        ->paginate($perPage);

    $orders->getCollection()->transform(function ($order) {
        $order->item_count = $order->items?->count() ?? 0;
        return $order;
    });

    return response()->json([
        'success' => true,
        'message' => 'Confirmed orders retrieved successfully',
        'data' => $orders->items(),
        'meta' => [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'from' => $orders->firstItem(),
            'to' => $orders->lastItem(),
        ],
    ]);
}

 public function rejectedOrders(Request $request)
 {
 $waiterId = auth()->id();
 $perPage = (int) $request->get('per_page', 15);

 $query = Order::with(['table', 'items.menuItem', 'bill'])
 ->where('waiter_id', $waiterId)
 ->whereIn('status', ['cancelled', 'void']);

 if ($request->filled('search')) {
 $search = trim($request->search);

 $query->where(function ($q) use ($search) {
 $q->where('order_number', 'like', "%{$search}%")
 ->orWhere('customer_name', 'like', "%{$search}%")
 ->orWhere('customer_phone', 'like', "%{$search}%");
 });
 }

 if ($request->filled('order_type') && in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)) {
 $query->where('order_type', $request->order_type);
 }

 $orders = $query
 ->orderByDesc('ordered_at')
 ->paginate($perPage);

 $orders->getCollection()->transform(function ($order) {
 $order->item_count = $order->items?->count() ?? 0;
 return $order;
 });

 return response()->json([
 'success' => true,
 'data' => $orders,
 ]);
 }

 public function readyOrders(Request $request)
{
    $waiterId = auth()->id();
    $perPage = max(1, (int) $request->get('per_page', 10));

    $query = Order::with(['table', 'items.menuItem', 'bill'])
        ->where('waiter_id', $waiterId)
        ->where('status', 'ready');

    if ($request->filled('search')) {
        $search = trim((string) $request->search);

        $query->where(function ($q) use ($search) {
            $q->where('order_number', 'like', "%{$search}%")
              ->orWhere('customer_name', 'like', "%{$search}%")
              ->orWhere('customer_phone', 'like', "%{$search}%");
        });
    }

    if (
        $request->filled('order_type') &&
        in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
    ) {
        $query->where('order_type', $request->order_type);
    }

    $orders = $query
        ->orderByDesc('ordered_at')
        ->paginate($perPage);

    $orders->getCollection()->transform(function ($order) {
        $order->item_count = $order->items?->count() ?? 0;
        return $order;
    });

    return response()->json([
        'success' => true,
        'message' => 'Ready orders retrieved successfully',
        'data' => $orders->items(),
        'meta' => [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'from' => $orders->firstItem(),
            'to' => $orders->lastItem(),
        ],
    ]);
}
 public function markServed(Request $request, $id)
 {
 return DB::transaction(function () use ($id) {

 $order = Order::with(['items', 'table', 'bill'])
 ->lockForUpdate()
 ->findOrFail($id);

 if ($order->waiter_id !== auth()->id()) {
 return response()->json([
 'success' => false,
 'message' => 'You are not allowed to serve this order.',
 ], 403);
 }

 if ($order->status !== 'ready') {
 return response()->json([
 'success' => false,
 'message' => 'Only ready orders can be marked as served.',
 ], 422);
 }

 // update order status
 $order->update([
 'status' => 'served',
 ]);

 $this->auditLogger->log(request(), auth()->id(), 'Order', $order->id, 'order_served', null, $order->toArray(), 'Order marked served by waiter.');

 // update order items
 OrderItem::where('order_id', $order->id)
 ->update([
 'item_status' => 'served',
 ]);

 if ($order->bill && $order->bill->status === 'paid') {
 $order->update([
 'status' => 'completed',
 'completed_at' => now(),
 ]);
 }

 return response()->json([
 'success' => true,
 'message' => 'Order marked as served successfully.',
 'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
 ]);
 });
 }
 public function servedOrders(Request $request)
 {
 $waiterId = auth()->id();
 $perPage = (int) $request->get('per_page', 15);

 $query = Order::with(['table', 'items.menuItem', 'bill.payments'])
 ->where('waiter_id', $waiterId)
 ->where('status', 'served');

 if ($request->filled('search')) {
 $search = trim($request->search);

 $query->where(function ($q) use ($search) {
 $q->where('order_number', 'like', "%{$search}%")
 ->orWhere('customer_name', 'like', "%{$search}%")
 ->orWhere('customer_phone', 'like', "%{$search}%");
 });
 }

 if (
 $request->filled('order_type') &&
 in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
 ) {
 $query->where('order_type', $request->order_type);
 }

 $orders = $query
 ->orderByDesc('ordered_at')
 ->paginate($perPage);

 $orders->getCollection()->transform(function ($order) {
 $order->item_count = $order->items?->count() ?? 0;
 $order->bill_status = $order->bill?->status;
 $order->paid_amount = $order->bill?->paid_amount ?? 0;
 $order->balance = $order->bill?->balance ?? 0;
 return $order;
 });

 return response()->json([
 'success' => true,
 'data' => $orders,
 ]);
 }

public function waiterSoldItems(Request $request)
{
   $this->authorize('waiterReports', Order::class);
$user = $request->user();

$soldStatuses = ['served', 'delivered', 'completed', 'paid'];
$validOrderTypes = ['dine_in', 'takeaway', 'delivery'];

$hasSku = Schema::hasColumn('menu_items', 'sku');
$hasCategoryId = Schema::hasColumn('menu_items', 'category_id');
$hasCategoryTable = Schema::hasTable('menu_categories');

$baseQuery = OrderItem::query()
->join('orders', 'order_items.order_id', '=', 'orders.id')
->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
->where('orders.waiter_id', $user->id)
->whereIn('orders.status', $soldStatuses);

if ($hasCategoryId && $hasCategoryTable) {
$baseQuery->leftJoin('menu_categories', 'menu_items.category_id', '=', 'menu_categories.id');
}

if ($request->filled('date_from')) {
$baseQuery->whereDate('orders.created_at', '>=', $request->date_from);
}

if ($request->filled('date_to')) {
$baseQuery->whereDate('orders.created_at', '<=', $request->date_to);
    }

    if ($request->filled('order_type') && in_array($request->order_type, $validOrderTypes, true)) {
    $baseQuery->where('orders.order_type', $request->order_type);
    }

    if ($request->filled('category_id') && $hasCategoryId) {
    $baseQuery->where('menu_items.category_id', $request->category_id);
    }

    if ($request->filled('search')) {
    $search = trim((string) $request->search);

    $baseQuery->where(function ($q) use ($search, $hasSku) {
    $q->where('menu_items.name', 'like', "%{$search}%")
    ->orWhere('orders.order_number', 'like', "%{$search}%");

    if ($hasSku) {
    $q->orWhere('menu_items.sku', 'like', "%{$search}%");
    }
    });
    }

    $summaryQuery = clone $baseQuery;

    $summary = [
    'total_sold_qty' => (int) (clone $summaryQuery)->sum('order_items.quantity'),
    'total_sales' => (float) (clone $summaryQuery)->sum(DB::raw('order_items.quantity * order_items.unit_price')),
    'total_orders' => (int) (clone $summaryQuery)->distinct('order_items.order_id')->count('order_items.order_id'),
    'distinct_items' => (int) (clone
    $summaryQuery)->distinct('order_items.menu_item_id')->count('order_items.menu_item_id'),
    ];

    $selects = [
    'order_items.menu_item_id',
    'menu_items.name as item_name',
    DB::raw('SUM(order_items.quantity) as total_qty'),
    DB::raw('SUM(order_items.quantity * order_items.unit_price) as total_sales'),
    DB::raw('AVG(order_items.unit_price) as avg_unit_price'),
    DB::raw('COUNT(DISTINCT order_items.order_id) as total_orders'),
    ];

    $groupBys = [
    'order_items.menu_item_id',
    'menu_items.name',
    ];

    if ($hasSku) {
    $selects[] = 'menu_items.sku as item_sku';
    $groupBys[] = 'menu_items.sku';
    } else {
    $selects[] = DB::raw('NULL as item_sku');
    }

    if ($hasCategoryId && $hasCategoryTable) {
    $selects[] = 'menu_categories.name as category_name';
    $groupBys[] = 'menu_categories.name';
    } else {
    $selects[] = DB::raw('NULL as category_name');
    }

    $rows = (clone $baseQuery)
    ->select($selects)
    ->groupBy($groupBys)
    ->orderByDesc('total_qty')
    ->paginate((int) $request->get('per_page', 20));

    return response()->json([
    'success' => true,
    'data' => [
    'summary' => $summary,
    'items' => $rows,
    ],
    ]);
    }

    public function categories()
    {
        $this->authorize('waiterReports', Order::class);
    if (!Schema::hasTable('menu_categories')) {
    return response()->json([
    'success' => true,
    'data' => [],
    ]);
    }

    $rows = DB::table('menu_categories')
    ->select('id', 'name')
    ->orderBy('name')
    ->get();

    return response()->json([
    'success' => true,
    'data' => $rows,
    ]);
    }

    /**
    * Get pending orders for waiter
    */
   /**
   * Get pending orders for waiter (orders that need initial confirmation/processing)
   */
  public function pendingOrders(Request $request)
{
    $this->authorize('viewAny', Order::class);

    $perPage = max(1, (int) $request->get('per_page', 10));

    $query = Order::with(['table', 'items.menuItem', 'bill'])
        ->where('status', 'pending'); // returns ALL pending orders

    // Search filter
    if ($request->filled('search')) {
        $search = trim((string) $request->search);

        $query->where(function ($q) use ($search) {
            $q->where('order_number', 'like', "%{$search}%")
              ->orWhere('customer_name', 'like', "%{$search}%")
              ->orWhere('customer_phone', 'like', "%{$search}%");
        });
    }

    // Order type filter
    if (
        $request->filled('order_type') &&
        in_array($request->order_type, ['dine_in', 'takeaway', 'delivery'], true)
    ) {
        $query->where('order_type', $request->order_type);
    }

    // Sorting
    $query->orderBy(
        'ordered_at',
        $request->get('sort', 'desc') === 'asc' ? 'asc' : 'desc'
    );

    $orders = $query->paginate($perPage);

    // Add item_count
    $orders->getCollection()->transform(function ($order) {
        $order->item_count = $order->items?->count() ?? 0;
        return $order;
    });

    return response()->json([
        'success' => true,
        'message' => 'Pending orders retrieved successfully',
        'data' => $orders->items(),
        'meta' => [
            'current_page' => $orders->currentPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'from' => $orders->firstItem(),
            'to' => $orders->lastItem(),
        ],
    ]);
}

public function confirmOrder(Request $request, $id)
{
    $order = Order::findOrFail($id);
    $this->authorize('update', $order);
return DB::transaction(function () use ($id) {
$order = Order::with(['items.menuItem', 'table', 'bill'])
->lockForUpdate()
->findOrFail($id);

if (!in_array($order->status, ['pending', 'confirmed'], true)) {
return response()->json([
'success' => false,
'message' => 'Only pending or confirmed orders can be confirmed.',
], 422);
}

// Update order status. Confirm is idempotent because waiter orders are confirmed at creation.
$order->update([
'status' => 'confirmed',
'waiter_id' => auth()->id(),
]);

$this->auditLogger->log(request(), auth()->id(), 'Order', $order->id, 'order_confirmed', null, $order->toArray(), 'Order confirmed by waiter.');

// Update order items status and deduct inventory once per confirmed item
$orderItems = OrderItem::where('order_id', $order->id)->lockForUpdate()->get();
foreach ($orderItems as $orderItem) {
$orderItem->update([
'item_status' => 'confirmed',
]);
$this->inventoryDeductionService->deductForOrderItem($orderItem->fresh(), (int) auth()->id());
}

// Update bill/payment status
if ($order->bill) {
$order->bill->update([
'status' => 'issued',
]);
}

// Update kitchen tickets
if (Schema::hasTable('kitchen_tickets')) {
KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
$q->select('id')
->from('order_items')
->where('order_id', $order->id);
})->update([
'status' => 'confirmed',
]);
}

// Update bar tickets
if (Schema::hasTable('bar_tickets')) {
BarTicket::whereIn('order_item_id', function ($q) use ($order) {
$q->select('id')
->from('order_items')
->where('order_id', $order->id);
})->update([
'status' => 'confirmed',
]);
}

$hasKitchen = $order->items->contains(fn ($item) => $item->station === 'kitchen');
$hasBar = $order->items->contains(fn ($item) => $item->station === 'bar');

if ($hasKitchen) {
$this->notificationService->notifyUsersByPermission(
'kds.kitchen',
'New kitchen order',
"Order {$order->order_number} is ready for kitchen processing.",
['kind' => 'kitchen_order_created', 'order_id' => $order->id, 'order_number' => $order->order_number]
);
}

if ($hasBar) {
$this->notificationService->notifyUsersByPermission(
'kds.bar',
'New bar order',
"Order {$order->order_number} is ready for bar processing.",
['kind' => 'bar_order_created', 'order_id' => $order->id, 'order_number' => $order->order_number]
);
}

return response()->json([
'success' => true,
'message' => 'Order confirmed successfully.',
'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
]);
});
}

   public function markPreparing(Request $request, $id)
   {
       $order = Order::findOrFail($id);
       $this->authorize('update', $order);
   return DB::transaction(function () use ($id) {
   $order = Order::with(['items', 'table', 'bill'])
   ->lockForUpdate()
   ->findOrFail($id);

   if ($order->waiter_id !== auth()->id()) {
   return response()->json([
   'success' => false,
   'message' => 'You are not authorized to update this order.',
   ], 403);
   }

   if ($order->status !== 'confirmed') {
   return response()->json([
   'success' => false,
   'message' => 'Only confirmed orders can be marked as preparing.',
   ], 422);
   }

   // Update order status
   $order->update([
   'status' => 'in_progress',
   ]);

   // Update order items status to preparing
   OrderItem::where('order_id', $order->id)
   ->where('item_status', 'confirmed')
   ->update([
   'item_status' => 'preparing',
   ]);

   // Update kitchen tickets based on station
   KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id)
   ->where('station', 'kitchen');
   })->update([
   'status' => 'preparing',
   ]);

   // Update bar tickets based on station
   BarTicket::whereIn('order_item_id', function ($q) use ($order) {
   $q->select('id')
   ->from('order_items')
   ->where('order_id', $order->id)
   ->where('station', 'bar');
   })->update([
   'status' => 'preparing',
   ]);

   return response()->json([
   'success' => true,
   'message' => 'Order marked as preparing.',
   'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
   ]);
   });
   }

   public function cashierStore(StoreOrderRequest $request)
   {
       $this->authorize('create', Order::class);
   
       try {
           $validated = $request->validated();
   
           $validated['order_type'] = 'takeaway';
           $validated['table_id'] = null;
           $validated['customer_name'] = 'Guest';
           $validated['customer_phone'] = null;
           $validated['customer_address'] = null;
           $validated['_source'] = 'cashier';
   
           if (empty($validated['waiter_id'])) {
               return response()->json([
                   'success' => false,
                   'message' => 'Waiter is required for cashier order entry.',
               ], 422);
           }
   
           $order = $this->waiterOrderService->createOrder(
               $validated,
               (int) auth()->id()
           );
   
           return response()->json([
               'success' => true,
               'message' => 'Cashier order created successfully',
               'data' => $order,
           ], 201);
       } catch (\Exception $e) {
           return response()->json([
               'success' => false,
               'message' => $e->getMessage(),
           ], 422);
       }
   }

    
        
}
