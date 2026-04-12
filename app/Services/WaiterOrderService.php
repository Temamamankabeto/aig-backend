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
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WaiterOrderService
{
    public function __construct(
        private OrderNumberService $orderNumberService,
        private InventoryDeductionService $inventoryDeductionService
    ) {
    }

    public function createOrder(array $data, int $authUserId): Order
    {
        return DB::transaction(function () use ($data, $authUserId) {
            Log::info('WaiterOrderService::createOrder started.', [
                'auth_user_id' => $authUserId,
                'source' => $data['_source'] ?? 'waiter',
            ]);

            $orderNumber = $this->orderNumberService->generate();

            $subtotal = 0.0;
            $preparedItems = [];

            foreach (($data['items'] ?? []) as $item) {
                $menuItemId = (int) ($item['menu_item_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);

                Log::info('Processing order item.', [
                    'menu_item_id' => $menuItemId,
                    'quantity' => $quantity,
                ]);

                if ($menuItemId <= 0) {
                    throw new RuntimeException('Invalid menu item.');
                }

                if ($quantity <= 0) {
                    throw new RuntimeException('Item quantity must be greater than zero.');
                }

                $menuItem = MenuItem::findOrFail($menuItemId);

                if (!$menuItem->is_active || !$menuItem->is_available) {
                    throw new RuntimeException("Item {$menuItem->name} is not available.");
                }

                $unitPrice = round((float) $menuItem->price, 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $itemNote = $item['notes'] ?? $item['note'] ?? null;
                $itemModifiers = $item['modifiers'] ?? null;

                $preparedItems[] = [
                    'menu_item_id' => $menuItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'station' => $menuItem->type === 'food' ? 'kitchen' : 'bar',
                    'notes' => is_string($itemNote) ? (trim($itemNote) ?: null) : null,
                    'modifiers' => is_array($itemModifiers) ? $itemModifiers : null,
                ];

                Log::info('Order item prepared.', [
                    'menu_item_id' => $menuItem->id,
                    'menu_item_name' => $menuItem->name,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'tracking_mode' => $menuItem->inventory_tracking_mode
                        ?? ($menuItem->has_ingredients ? 'recipe' : 'none'),
                ]);
            }

            if (empty($preparedItems)) {
                throw new RuntimeException('At least one valid order item is required.');
            }

            $subtotal = round($subtotal, 2);
            $tax = round($subtotal * 0.10, 2);
            $serviceCharge = round($subtotal * 0.05, 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);

            if ($discount < 0) {
                $discount = 0;
            }

            $total = round(($subtotal + $tax + $serviceCharge) - $discount, 2);

            if ($total < 0) {
                $total = 0;
            }

            Log::info('Order totals calculated.', [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'discount' => $discount,
                'total' => $total,
            ]);

            $source = (string) ($data['_source'] ?? 'waiter');
            $isCashierOrder = $source === 'cashier';

            $orderType = (string) ($data['order_type'] ?? 'dine_in');
            $tableId = $orderType === 'dine_in'
                ? (!empty($data['table_id']) ? (int) $data['table_id'] : null)
                : null;

            if ($orderType === 'dine_in' && empty($tableId)) {
                throw new RuntimeException('Table is required for dine-in orders.');
            }

            if ($orderType !== 'dine_in') {
                $tableId = null;
            }

            if ($orderType === 'delivery' && empty($data['customer_address'])) {
                throw new RuntimeException('Customer address is required for delivery orders.');
            }

            if ($orderType === 'dine_in' && $tableId) {
                DiningTable::query()
                    ->where('id', $tableId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $orderStatus = $isCashierOrder ? 'confirmed' : 'pending';
            $itemStatus = $isCashierOrder ? 'confirmed' : 'pending';
            $ticketStatus = $isCashierOrder ? 'confirmed' : 'pending';

            $billStatus = 'issued';
            $issuedAt = $isCashierOrder ? now() : null;

            $customerName = isset($data['customer_name'])
                ? (trim((string) $data['customer_name']) ?: 'Guest')
                : 'Guest';

            $customerPhone = isset($data['customer_phone'])
                ? (trim((string) $data['customer_phone']) ?: null)
                : null;

            $customerAddress = isset($data['customer_address'])
                ? (trim((string) $data['customer_address']) ?: null)
                : null;

            $orderNotes = isset($data['notes'])
                ? (trim((string) $data['notes']) ?: null)
                : null;

            $waiterId = !empty($data['waiter_id'])
                ? (int) $data['waiter_id']
                : $authUserId;

            Log::info('Creating order header.', [
                'order_number' => $orderNumber,
                'order_type' => $orderType,
                'table_id' => $tableId,
                'waiter_id' => $waiterId,
                'is_cashier_order' => $isCashierOrder,
            ]);

            $order = Order::create([
                'order_number' => $orderNumber,
                'order_type' => $orderType,
                'table_id' => $tableId,
                'created_by' => $authUserId,
                'waiter_id' => $waiterId,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_address' => $customerAddress,
                'status' => $orderStatus,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'discount' => $discount,
                'total' => $total,
                'notes' => $orderNotes,
                'ordered_at' => now(),
            ]);

            foreach ($preparedItems as $itemData) {
                $itemData['order_id'] = $order->id;
                $itemData['item_status'] = $itemStatus;

                $orderItem = OrderItem::create($itemData);

                if ($orderItem->station === 'kitchen') {
                    KitchenTicket::create([
                        'order_item_id' => $orderItem->id,
                        'status' => $ticketStatus,
                    ]);
                } else {
                    BarTicket::create([
                        'order_item_id' => $orderItem->id,
                        'status' => $ticketStatus,
                    ]);
                }

                Log::info('Order item and station ticket created.', [
                    'order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'menu_item_id' => $orderItem->menu_item_id,
                    'station' => $orderItem->station,
                    'item_status' => $orderItem->item_status,
                    'ticket_status' => $ticketStatus,
                ]);
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

            Log::info('Bill created for order.', [
                'order_id' => $order->id,
                'bill_number' => 'BILL-' . $orderNumber,
                'bill_status' => $billStatus,
            ]);

            if ($orderType === 'dine_in' && !empty($tableId)) {
                DiningTable::where('id', $tableId)->update([
                    'status' => 'occupied',
                ]);

                Log::info('Dining table marked as occupied.', [
                    'table_id' => $tableId,
                    'order_id' => $order->id,
                ]);
            }

            Log::info('Checking if inventory deduction is required.', [
                'order_id' => $order->id,
                'is_cashier_order' => $isCashierOrder,
            ]);

            // For cashier-created orders, validate stock and deduct immediately.
            // If any ingredient/direct stock is insufficient, exception is thrown
            // and the whole transaction rolls back.
            if ($isCashierOrder) {
                $order->load('items.menuItem');

                Log::info('Starting inventory deduction for cashier order.', [
                    'order_id' => $order->id,
                    'items_count' => $order->items->count(),
                ]);

                $this->inventoryDeductionService->deductForOrder($order, $authUserId);

                Log::info('Inventory deduction completed for cashier order.', [
                    'order_id' => $order->id,
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