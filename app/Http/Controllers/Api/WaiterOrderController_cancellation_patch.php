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

    // ... keep your existing methods here ...

    /**
     * Waiter can only request cancellation.
     * Final approval / void must be done by Food Controller or Manager.
     */
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

            // Notify approvers instead of letting waiter void directly.
            $reason = $data['reason'] ?? 'No reason provided';

            $this->notificationService->notifyUsersByPermission(
                'food-controller.dashboard',
                'Order cancellation requested',
                "Order {$order->order_number} has a cancellation request pending approval. Reason: {$reason}",
                [
                    'kind' => 'order_cancel_requested',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'requested_by' => auth()->id(),
                ]
            );

            $this->notificationService->notifyUsersByPermission(
                'manager.dashboard',
                'Order cancellation requested',
                "Order {$order->order_number} has a cancellation request pending approval. Reason: {$reason}",
                [
                    'kind' => 'order_cancel_requested',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'requested_by' => auth()->id(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Cancellation requested successfully and sent for approval.',
                'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
            ]);
        });
    }

    /**
     * Keep this method for Food Controller / Manager routes only.
     * Remove it from waiter routes.
     */
    public function approveCancel(Request $request, $id)
    {
        $this->authorize('approveCancel', Order::class);

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
                ->update(['item_status' => 'cancelled']);

            KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')->from('order_items')->where('order_id', $order->id);
            })
                ->whereNotIn('status', ['ready'])
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ]);

            BarTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')->from('order_items')->where('order_id', $order->id);
            })
                ->whereNotIn('status', ['ready'])
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ]);

            if ($order->bill && in_array($order->bill->status, ['draft', 'issued', 'partial', 'confirmed'], true)) {
                $order->bill->update([
                    'status' => 'void',
                    'balance' => 0,
                ]);
            }

            if ($order->order_type === 'dine_in' && $order->table_id) {
                optional($order->table)->update(['status' => 'available']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order cancellation approved successfully.',
                'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
            ]);
        });
    }

    /**
     * Keep this method for Food Controller / Manager routes only.
     * Remove it from waiter routes.
     */
    public function voidOrder(Request $request, $id)
    {
        $this->authorize('voidOrder', Order::class);

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
                ->update(['item_status' => 'cancelled']);

            KitchenTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')->from('order_items')->where('order_id', $order->id);
            })
                ->whereNotIn('status', ['ready'])
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ]);

            BarTicket::whereIn('order_item_id', function ($q) use ($order) {
                $q->select('id')->from('order_items')->where('order_id', $order->id);
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
                optional($order->table)->update(['status' => 'available']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order voided successfully.',
                'data' => $order->fresh(['items.menuItem', 'bill', 'table']),
            ]);
        });
    }
}
