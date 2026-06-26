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
        private InventoryDeductionService $inventoryDeductionService,
        private CreditOrderService $creditOrderService
    ) {}

    public function createOrder(array $data, int $authUserId): Order
    {
        try {
            return DB::transaction(function () use ($data, $authUserId) {
                $orderNumber = $this->orderNumberService->generate();
                $subtotal = 0.0;
                $preparedItems = [];

                foreach (($data['items'] ?? []) as $item) {
                    $menuItemId = (int) ($item['menu_item_id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 0);
                    if ($menuItemId <= 0) throw new RuntimeException('Invalid menu item.');
                    if ($quantity <= 0) throw new RuntimeException('Item quantity must be greater than zero.');

                    $menuItem = MenuItem::findOrFail($menuItemId);
                    if (!$menuItem->is_active || !$menuItem->is_available) throw new RuntimeException("Item {$menuItem->name} is not available.");

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
                }

                if (empty($preparedItems)) throw new RuntimeException('At least one valid order item is required.');

                $subtotal = round($subtotal, 2);
                $tax = round($subtotal * 0.10, 2);
                $serviceCharge = round($subtotal * 0.05, 2);
                $discount = max(0, round((float) ($data['discount'] ?? 0), 2));
                $total = max(0, round(($subtotal + $tax + $serviceCharge) - $discount, 2));

                $paymentType = (string) ($data['payment_type'] ?? 'regular');
                $isCreditOrder = $paymentType === 'credit';

                $orderType = (string) ($data['order_type'] ?? 'dine_in');
                $tableId = $orderType === 'dine_in' ? (!empty($data['table_id']) ? (int) $data['table_id'] : null) : null;
                if ($orderType === 'dine_in' && empty($tableId)) throw new RuntimeException('Table is required for dine-in orders.');
                if ($orderType === 'delivery' && empty($data['customer_address'])) throw new RuntimeException('Customer address is required for delivery orders.');

                if ($orderType === 'dine_in' && $tableId) {
                    DiningTable::query()->where('id', $tableId)->where('is_active', true)->lockForUpdate()->firstOrFail();
                }

                $orderStatus = 'confirmed';
                $itemStatus = 'confirmed';
                $ticketStatus = 'confirmed';
                $billStatus = 'issued';
                $issuedAt = now();

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'order_type' => $orderType,
                    'table_id' => $tableId,
                    'created_by' => $authUserId,
                    'waiter_id' => !empty($data['waiter_id']) ? (int) $data['waiter_id'] : $authUserId,
                    'customer_name' => trim($data['customer_name'] ?? 'Guest'),
                    'customer_phone' => trim($data['customer_phone'] ?? '') ?: null,
                    'customer_address' => trim($data['customer_address'] ?? '') ?: null,
                    'status' => $orderStatus,
                    'payment_type' => $isCreditOrder ? 'credit' : $paymentType,
                    'credit_status' => $isCreditOrder ? 'credit_approved' : null,
                    'credit_account_id' => $isCreditOrder ? (int) ($data['credit_account_id'] ?? 0) : null,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'service_charge' => $serviceCharge,
                    'discount' => $discount,
                    'total' => $total,
                    'notes' => trim($data['notes'] ?? '') ?: null,
                    'ordered_at' => now(),
                ]);

                foreach ($preparedItems as $itemData) {
                    $itemData['order_id'] = $order->id;
                    $itemData['item_status'] = $itemStatus;
                    $orderItem = OrderItem::create($itemData);
                    ($orderItem->station === 'kitchen' ? KitchenTicket::class : BarTicket::class)::create([
                        'order_item_id' => $orderItem->id,
                        'status' => $ticketStatus,
                    ]);
                }

                $bill = Bill::create([
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
                    'bill_type' => $isCreditOrder ? 'credit' : 'normal',
                    'credit_status' => $isCreditOrder ? 'credit_approved' : null,
                    'due_date' => null,
                    'issued_at' => $issuedAt,
                ]);

                if ($isCreditOrder) {
                    $this->creditOrderService->createForBill(
                        $bill,
                        (int) $data['credit_account_id'],
                        $authUserId,
                        null,
                        $data['credit_notes'] ?? null,
                        false,
                        !empty($data['credit_account_user_id']) ? (int) $data['credit_account_user_id'] : null
                    );
                }

                if ($orderType === 'dine_in' && !empty($tableId)) DiningTable::where('id', $tableId)->update(['status' => 'occupied']);

                $order->load('items.menuItem');
                $this->inventoryDeductionService->deductForOrder($order, $authUserId);

                return $order->load(['items.menuItem', 'creator', 'waiter', 'table', 'bill.creditOrder.account', 'creditOrder.account', 'creditOrder.authorizedUser']);
            });
        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'user_id' => $authUserId, 'payload' => $data]);
            throw new RuntimeException($e->getMessage());
        }
    }
}
