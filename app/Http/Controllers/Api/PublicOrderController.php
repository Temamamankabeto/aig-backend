<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BarTicket;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PublicOrderController extends Controller
{
    public function __construct(
        private OrderNumberService $orderNumberService
    ) {
    }

    public function tables()
    {
        $tables = DiningTable::where('is_active', true)
            ->where('is_public', true)
            ->where('status', 'available')
            ->orderBy('sort_order')
            ->orderBy('table_number')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'table_number' => $t->table_number,
                'name' => $t->name ?: ('Table ' . $t->table_number),
                'capacity' => $t->capacity,
                'section' => $t->section,
                'status' => $t->status,
            ]);

        return response()->json([
            'success' => true,
            'data' => $tables,
        ]);
    }

    public function menu(Request $request)
    {
        $categories = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $itemsQuery = MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('category')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $itemsQuery->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $itemsQuery->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $items = $itemsQuery->get();

        return response()->json([
            'success' => true,
            'data' => $items,
            'categories' => $categories,
        ]);
    }

    public function categories()
    {
        $categories = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $user = null;
        $token = $request->bearerToken();

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable;
            }
        }

        if ($request->order_type === 'delivery' && !$user) {
            return response()->json([
                'success' => false,
                'requires_auth' => true,
                'message' => 'Please login or register before placing a delivery order.',
            ], 401);
        }

        if ($request->order_type === 'dine_in' && $request->filled('table_id')) {
            $table = DiningTable::find($request->integer('table_id'));
            if (! $table || ! $table->is_active || ! ($table->is_public ?? true) || $table->status !== 'available') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected table is not available for public dine-in ordering.',
                ], 422);
            }
        }

        $rules = [
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'items.*.note' => 'nullable|string',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_address' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
            
        ];

        if ($request->order_type === 'dine_in') {
            $rules['table_id'] = 'required|exists:dining_tables,id';
        }

        if ($request->order_type === 'delivery') {
            $rules['customer_address'] = 'required|string|max:500';
        }

        $validated = $request->validate($rules);

        try {
            return DB::transaction(function () use ($validated, $user) {
                $orderNumber = $this->orderNumberService->generate();

                $subtotal = 0;
                $preparedItems = [];

                foreach ($validated['items'] as $item) {
                    $menuItem = MenuItem::findOrFail($item['menu_item_id']);

                    if (!$menuItem->is_active || !$menuItem->is_available) {
                        throw new \RuntimeException("Item {$menuItem->name} is not available");
                    }

                    $quantity = (int) $item['quantity'];
                    $unitPrice = (float) $menuItem->price;
                    $lineTotal = $unitPrice * $quantity;

                    $subtotal += $lineTotal;

                    $preparedItems[] = [
                        'menu_item_id' => $menuItem->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                        'station' => $menuItem->type === 'food' ? 'kitchen' : 'bar',
                        'item_status' => 'pending',
                        'notes' => $item['notes'] ?? $item['note'] ?? null,
                    ];
                }

                $tax = round($subtotal * 0.10, 2);
                $serviceCharge = round($subtotal * 0.05, 2);
                $discount = 0;
                $total = round($subtotal + $tax + $serviceCharge - $discount, 2);

                $orderType = $validated['order_type'];
                $tableId = $orderType === 'dine_in'
                    ? $validated['table_id']
                    : null;

                $customerName = $orderType === 'delivery'
                    ? ($user->name ?? 'Customer')
                    : (!empty($validated['customer_name']) ? $validated['customer_name'] : 'Guest');

                $customerPhone = $orderType === 'delivery'
                    ? ($user->phone ?? null)
                    : ($validated['customer_phone'] ?? null);

                    $order = Order::create([
                        'order_number' => $orderNumber,
                        'order_type' => $orderType,
                        'table_id' => $tableId,
                        'created_by' => $user?->id ?? config('app.guest_user_id'),
                        'waiter_id' => null,
                        'customer_name' => $customerName ?? 'Guest',
                        'customer_phone' => $customerPhone,
                        'customer_address' => $validated['customer_address'] ?? null,
                        'rider_id' => null,
                        'status' => 'pending',
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'service_charge' => $serviceCharge,
                        'discount' => $discount,
                        'total' => $total,
                        'notes' => $validated['notes'] ?? null,
                        'ordered_at' => now(),
                    ]);
                foreach ($preparedItems as $itemData) {
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $itemData['menu_item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'line_total' => $itemData['line_total'],
                        'station' => $itemData['station'],
                        'item_status' => $itemData['item_status'],
                        'notes' => $itemData['notes'],
                    ]);

                    if ($orderItem->station === 'kitchen') {
                        KitchenTicket::create([
                            'order_item_id' => $orderItem->id,
                            'status' => 'pending',
                        ]);
                    } else {
                        BarTicket::create([
                            'order_item_id' => $orderItem->id,
                            'status' => 'pending',
                        ]);
                    }
                }

                $bill = Bill::create([
                    'order_id' => $order->id,
                    'bill_number' => 'BILL-' . $orderNumber,
                    'status' => 'issued',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'service_charge' => $serviceCharge,
                    'discount' => $discount,
                    'total' => $total,
                    'paid_amount' => 0,
                    'balance' => $total,
                    'issued_at' => now(),
                ]);

                if ($orderType === 'dine_in' && !empty($tableId)) {
                    DiningTable::where('id', $tableId)->update([
                        'status' => 'occupied',
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order placed successfully',
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'bill_id' => $bill->id,
                        'bill_number' => $bill->bill_number,
                        'total' => (float) $total,
                        'status' => $order->status,
                    ],
                ], 201);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show($orderNumber)
    {
        $order = Order::with([
            'items.menuItem',
            'items.kitchenTicket',
            'items.barTicket',
            'table',
        ])->where('order_number', $orderNumber)->firstOrFail();

        $bill = Bill::where('order_id', $order->id)->first();

        $totalItems = $order->items->count();
        $completedItems = $order->items->filter(fn ($item) =>
            in_array($item->item_status, ['ready', 'served', 'delivered'])
        )->count();

        $progress = $totalItems > 0
            ? round(($completedItems / $totalItems) * 100)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order,
                'bill' => $bill,
                'progress' => $progress,
                'estimated_remaining' => $this->estimateRemainingTime($order),
            ],
        ]);
    }

    private function calculateWaitTime($items)
    {
        $hasFood = collect($items)->contains('station', 'kitchen');
        $hasDrink = collect($items)->contains('station', 'bar');

        $waitTime = 0;
        if ($hasFood) {
            $waitTime += 15;
        }
        if ($hasDrink) {
            $waitTime += 5;
        }

        return $waitTime;
    }

    private function estimateRemainingTime($order)
    {
        $pendingItems = $order->items->filter(fn ($item) =>
            !in_array($item->item_status, ['ready', 'served', 'delivered', 'cancelled', 'rejected'])
        );

        if ($pendingItems->isEmpty()) {
            return 0;
        }

        $hasFood = $pendingItems->contains('station', 'kitchen');
        $hasDrink = $pendingItems->contains('station', 'bar');

        $remaining = 0;
        if ($hasFood) {
            $remaining += 10;
        }
        if ($hasDrink) {
            $remaining += 3;
        }

        return $remaining;
    }

    public function items(Request $request)
    {
        $query = MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('category')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $items = $query->get();

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}