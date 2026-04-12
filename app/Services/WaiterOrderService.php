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
use App\Services\InventoryDeductionService;
use App\Services\OrderNumberService;
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
    try {

        return DB::transaction(function () use ($data, $authUserId) {

            $orderNumber = $this->orderNumberService->generate();

            $subtotal = 0.0;
            $preparedItems = [];

            foreach (($data['items'] ?? []) as $item) {

                $menuItemId = (int) ($item['menu_item_id'] ?? 0);
                $quantity   = (int) ($item['quantity'] ?? 0);

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

                $itemNote      = $item['notes'] ?? $item['note'] ?? null;
                $itemModifiers = $item['modifiers'] ?? null;

                $preparedItems[] = [
                    'menu_item_id' => $menuItem->id,
                    'quantity'     => $quantity,
                    'unit_price'   => $unitPrice,
                    'line_total'   => $lineTotal,
                    'station'      => $menuItem->type === 'food' ? 'kitchen' : 'bar',
                    'notes'        => is_string($itemNote) ? (trim($itemNote) ?: null) : null,
                    'modifiers'    => is_array($itemModifiers) ? $itemModifiers : null,
                ];
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

            $source = (string) ($data['_source'] ?? 'waiter');
            $isCashierOrder = $source === 'cashier';

            $orderType = (string) ($data['order_type'] ?? 'dine_in');

            $tableId = $orderType === 'dine_in'
                ? (!empty($data['table_id']) ? (int) $data['table_id'] : null)
                : null;

            if ($orderType === 'dine_in' && empty($tableId)) {
                throw new RuntimeException('Table is required for dine-in orders.');
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

            $orderStatus  = $isCashierOrder ? 'confirmed' : 'pending';
            $itemStatus   = $isCashierOrder ? 'confirmed' : 'pending';
            $ticketStatus = $isCashierOrder ? 'confirmed' : 'pending';

            $billStatus = 'issued';
            $issuedAt   = $isCashierOrder ? now() : null;

            $order = Order::create([
                'order_number' => $orderNumber,
                'order_type'   => $orderType,
                'table_id'     => $tableId,
                'created_by'   => $authUserId,
                'waiter_id'    => !empty($data['waiter_id']) ? (int) $data['waiter_id'] : $authUserId,
                'customer_name'    => trim($data['customer_name'] ?? 'Guest'),
                'customer_phone'   => trim($data['customer_phone'] ?? '') ?: null,
                'customer_address' => trim($data['customer_address'] ?? '') ?: null,
                'status'       => $orderStatus,
                'subtotal'     => $subtotal,
                'tax'          => $tax,
                'service_charge'=> $serviceCharge,
                'discount'     => $discount,
                'total'        => $total,
                'notes'        => trim($data['notes'] ?? '') ?: null,
                'ordered_at'   => now(),
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

            if ($isCashierOrder) {
                $order->load('items.menuItem');
                $this->inventoryDeductionService->deductForOrder($order, $authUserId);
            }

            return $order->load([
                'items.menuItem',
                'creator',
                'waiter',
                'table',
                'bill',
            ]);
        });

    } catch (\Throwable $e) {

        Log::error('Order creation failed', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user_id' => $authUserId,
            'payload' => $data,
        ]);

        throw new RuntimeException($e->getMessage());
    }
}
}