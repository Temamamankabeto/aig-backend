<?php

namespace App\Http\Controllers;

use App\Models\BarTicket;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BarTicketController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $scope = $request->query('scope', 'today');

        $q = BarTicket::with([
            'orderItem.order',
            'orderItem.menuItem',
        ])->orderByDesc('id');

        if ($scope === 'today') {
            $q->whereIn('status', [ 'confirmed', 'preparing', 'ready'])
              ->whereDate('created_at', today());
        } elseif ($scope === 'all_open') {
            $q->whereIn('status', [ 'confirmed', 'preparing', 'ready']);
        }

        $tickets = $q->paginate((int) $request->query('per_page', 100));

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    public function accept(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = BarTicket::lockForUpdate()->with('orderItem')->findOrFail($id);
            $this->authorize('accept', $ticket);

            if ($ticket->status !== 'pending' && $ticket->status !== 'confirmed') {
                return response()->json(['success' => false, 'message' => 'Ticket not pending'], 422);
            }

            $ticket->update([
                'status' => 'preparing',
                'barman_id' => $request->user()->id,
                'started_at' => now(),
            ]);

            $ticket->orderItem ->order->update([
                'status' => 'in_progress',
            ]);

            $ticket->orderItem->update([
                'item_status' => 'preparing',
                'started_at' => now(),
            ]);

            $this->auditLogger->log($request, $request->user()->id, 'BarTicket', $ticket->id, 'bar_ticket_accepted', null, $ticket->toArray(), 'Bar ticket accepted.');

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function ready(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = BarTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);
            $this->authorize('ready', $ticket);

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
                'Bar item ready',
                "Bar items for order {$order->order_number} are ready.",
                ['kind' => 'bar_ready', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'order_number' => $order->order_number]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'BarTicket', $ticket->id, 'bar_ticket_ready', null, $ticket->toArray(), 'Bar ticket marked ready.');

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function delay(Request $request, $id)
    {
        $ticket = BarTicket::findOrFail($id);
        $this->authorize('delay', $ticket);
        $data = $request->validate([
            'delay_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $ticket = BarTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            $ticket->update([
                'status' => 'delayed',
                'delay_reason' => $data['delay_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'delayed']);
            $order = $ticket->orderItem->order;
            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Bar delay',
                "Bar reported a delay for order {$order->order_number}.",
                ['kind' => 'bar_delayed', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'reason' => $data['delay_reason']]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'BarTicket', $ticket->id, 'bar_ticket_delayed', null, $ticket->toArray(), 'Bar ticket delayed.');

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }

    public function reject(Request $request, $id)
    {
        $ticket = BarTicket::findOrFail($id);
        $this->authorize('reject', $ticket);
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $ticket = BarTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            $ticket->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'rejected']);
            $order = $ticket->orderItem->order;
            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Bar rejected item',
                "Bar rejected an item for order {$order->order_number}.",
                ['kind' => 'bar_rejected', 'order_id' => $order->id, 'ticket_id' => $ticket->id, 'reason' => $data['rejection_reason']]
            );
            $this->auditLogger->log($request, $request->user()?->id, 'BarTicket', $ticket->id, 'bar_ticket_rejected', null, $ticket->toArray(), 'Bar ticket rejected.');

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }


    public function served(Request $request, $id)
{
    return DB::transaction(function () use ($request, $id) {

        $ticket = BarTicket::lockForUpdate()
            ->with('orderItem.order')
            ->findOrFail($id);

        $this->authorize('served', $ticket);

        if ($ticket->status !== 'ready') {
            return response()->json([
                'success' => false,
                'message' => 'Ticket must be ready before serving.'
            ], 422);
        }

        // Update ticket
        $ticket->update([
            'status' => 'served',
        ]);

        // Update order item
        $ticket->orderItem->update([
            'item_status' => 'served',
            'served_at' => now(),
        ]);

        $order = $ticket->orderItem->order;

        // Check if all order items served
        $allServed = $order->items()
            ->whereNotIn('item_status', ['cancelled', 'rejected'])
            ->where('item_status', '!=', 'served')
            ->doesntExist();

        if ($allServed) {
            $order->update([
                'status' => 'served',
            ]);
        }

        // Notify waiter
        $this->notificationService->notifyUser(
            $order->waiter_id,
            'Bar item served',
            "Bar items for order {$order->order_number} have been served.",
            [
                'kind' => 'bar_served',
                'order_id' => $order->id,
                'ticket_id' => $ticket->id,
                'order_number' => $order->order_number
            ]
        );

        // Audit log
        $this->auditLogger->log(
            $request,
            $request->user()?->id,
            'BarTicket',
            $ticket->id,
            'bar_ticket_served',
            null,
            $ticket->toArray(),
            'Bar ticket marked served.'
        );

        return response()->json([
            'success' => true,
            'data' => $ticket->fresh()->load('orderItem.order')
        ]);
    });
}
}
