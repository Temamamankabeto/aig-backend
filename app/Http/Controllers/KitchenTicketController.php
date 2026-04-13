<?php

namespace App\Http\Controllers;

use App\Models\KitchenTicket;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenTicketController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 20);
    
        if ($perPage <= 0) {
            $perPage = 20;
        }
    
        $q = KitchenTicket::query()
            ->with([
                'orderItem.order.table',
                'orderItem.order.waiter',
                'orderItem.menuItem',
                'chef',
            ])
            ->orderByDesc('id');
    
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
    
        $rows = $q->paginate($perPage);
    
        $data = $rows->getCollection()->transform(function ($ticket) {
            return [
                'kitchen_ticket_id' => $ticket->id,
                'ticket_status' => $ticket->status,
    
                'order_id' => $ticket->orderItem?->order?->id,
                'order_number' => $ticket->orderItem?->order?->order_number,
    
                'order_item_id' => $ticket->orderItem?->id,
                'item_name' => $ticket->orderItem?->menuItem?->name,
                'image_path' => $ticket->orderItem?->menuItem?->image_path,
                'quantity' => $ticket->orderItem?->quantity,
                'order_item_status' => $ticket->orderItem?->item_status,
                'note' => $ticket->orderItem?->notes,
    
                'waiter_name' => $ticket->orderItem?->order?->waiter?->name,
                'table_number' => $ticket->orderItem?->order?->table?->table_number
                    ?? $ticket->orderItem?->order?->table?->name
                    ?? null,
            ];
        })->values();
    
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    public function accept(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem')->findOrFail($id);

            if ($ticket->status !== 'pending' && $ticket->status !== 'confirmed') {
                return response()->json(['success' => false, 'message' => 'Ticket not pending'], 422);
            }

            $ticket->update([
                'status' => 'preparing',
                'chef_id' => $request->user()->id,
                'started_at' => now(),
            ]);

            $ticket->orderItem->update([
                'item_status' => 'preparing',
                'started_at' => now(),
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'KitchenTicket', $ticket->id, 'kitchen_ticket_accepted', null, $ticket->toArray(), 'Kitchen ticket accepted.');

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function ready(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            if (!in_array($ticket->status, ['preparing','confirmed','delayed'], true)) {
                return response()->json(['success' => false, 'message' => 'Ticket not preparing'], 422);
            }

            $ticket->update([
                'status' => 'ready',
                'completed_at' => now(),
            ]);

            $ticket->orderItem->update([
                'item_status' => 'ready',
                'ready_at' => now(),
            ]);

            $order = $ticket->orderItem->order;
            $allReady = $order->items()
                ->whereNotIn('item_status', ['cancelled','rejected'])
                ->where('item_status', '!=', 'ready')
                ->doesntExist();

            if ($allReady && in_array($order->status, ['confirmed','in_progress'], true)) {
                $order->update(['status' => 'ready']);
            }

            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Kitchen item ready',
                "Kitchen items for order {$order->order_number} are ready.",
                ['kind' => 'kitchen_ready', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'order_number' => $order->order_number]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'KitchenTicket', $ticket->id, 'kitchen_ticket_ready', null, $ticket->toArray(), 'Kitchen ticket marked ready.');

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function delay(Request $request, $id)
    {
        $data = $request->validate([
            'delay_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            $ticket->update([
                'status' => 'delayed',
                'delay_reason' => $data['delay_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'delayed']);
            $order = $ticket->orderItem->order;
            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Kitchen delay',
                "Kitchen reported a delay for order {$order->order_number}.",
                ['kind' => 'kitchen_delayed', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'reason' => $data['delay_reason']]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'KitchenTicket', $ticket->id, 'kitchen_ticket_delayed', null, $ticket->toArray(), 'Kitchen ticket delayed.');

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            $ticket->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'rejected']);
            $order = $ticket->orderItem->order;
            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Kitchen rejected item',
                "Kitchen rejected an item for order {$order->order_number}.",
                ['kind' => 'kitchen_rejected', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'reason' => $data['rejection_reason']]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'KitchenTicket', $ticket->id, 'kitchen_ticket_rejected', null, $ticket->toArray(), 'Kitchen ticket rejected.');

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }
}
