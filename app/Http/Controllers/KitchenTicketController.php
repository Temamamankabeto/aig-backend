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
        $q = KitchenTicket::query()
            ->with(['orderItem.order','orderItem.menuItem','chef'])
            ->orderBy('id','desc');

        if ($request->filled('status')) $q->where('status', $request->status);

        $rows = $q->paginate((int)($request->get('per_page', 20)));
        return response()->json(['success' => true, 'data' => $rows]);
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
