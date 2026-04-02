<?php

namespace App\Services;

use App\Models\BarTicket;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WaiterOrderService
{
    public function __construct(
        private OrderNumberService $orderNumberService
    ) {
    }

    public function createOrder(array $data, int $authUserId): Order
    {
        return DB::transaction(function () use ($data, $authUserId) {
            $orderNumber = $this->orderNumberService->generate();

            $subtotal = 0;
            $preparedItems = [];

            foreach ($data['items'] as $item) {
                $menuItem = MenuItem::findOrFail($item['menu_item_id']);

                if (!$menuItem->is_active || !$menuItem->is_available) {
                    throw new RuntimeException("Item {$menuItem->name} is not available.");
                }

                $quantity = (int) ($item['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new RuntimeException("Invalid quantity for {$menuItem->name}.");
                }

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
                    'modifiers' => $item['modifiers'] ?? null,
                ];
            }

            $tax = round($subtotal * 0.10, 2);
            $serviceCharge = round($subtotal * 0.05, 2);
            $discount = (float) ($data['discount'] ?? 0);
            $total = round(($subtotal + $tax + $serviceCharge) - $discount, 2);

            if ($total < 0) {
                $total = 0;
            }

            $source = $data['_source'] ?? 'waiter';
            $isCashierOrder = $source === 'cashier';

            $orderType = $data['order_type'] ?? 'dine_in';
            $tableId = $orderType === 'dine_in' ? ($data['table_id'] ?? null) : null;

            if ($orderType === 'dine_in' && empty($tableId)) {
                throw new RuntimeException('Table is required for dine-in orders.');
            }

            if ($orderType !== 'dine_in') {
                $tableId = null;
            }

            if ($orderType === 'dine_in' && $tableId) {
                $table = DiningTable::where('id', $tableId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (!in_array($table->status, ['available', 'reserved'], true)) {
                    throw new RuntimeException("Table {$table->table_number} is not available.");
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Status rules
            |--------------------------------------------------------------------------
            | Waiter order:
            |   order status => confirmed
            |   bill status  => draft
            |
            | Cashier order:
            |   order status => pending
            |   bill status  => draft
            |
            | Bill should be issued later by:
            |   POST /cashier/bills/{orderId}/issue
            |--------------------------------------------------------------------------
            */
            $orderStatus = $isCashierOrder ? 'pending' : 'confirmed';
            $billStatus = 'draft';
            $issuedAt = null;

            $order = Order::create([
                'order_number' => $orderNumber,
                'order_type' => $orderType,
                'table_id' => $tableId,
                'created_by' => $authUserId,
                'waiter_id' => $data['waiter_id'] ?? $authUserId,
                'customer_name' => !empty($data['customer_name']) ? $data['customer_name'] : 'Guest',
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'status' => $orderStatus,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'ordered_at' => now(),
            ]);

            foreach ($preparedItems as $itemData) {
                $itemData['order_id'] = $order->id;

                $orderItem = OrderItem::create($itemData);

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

            Bill::create([
                'order_id' => $order->id,
                'bill_number' => 'BILL-' . $orderNumber,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'discount' => $discount,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'status' => $billStatus,
                'issued_at' => $issuedAt,
            ]);

            if ($orderType === 'dine_in' && !empty($tableId)) {
                DiningTable::where('id', $tableId)->update([
                    'status' => 'occupied',
                ]);
            }

            return $order->load([
                'items.menuItem',
                'creator',
                'waiter',
                'table',
                'bill',
            ]);
        });
    }
}