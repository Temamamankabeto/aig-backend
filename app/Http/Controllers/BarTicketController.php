<?php

namespace App\Http\Controllers;

use App\Models\BarTicket;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\OrderStatusService;
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
        $perPage = (int) $request->get('per_page', 20);
    
        if ($perPage <= 0) {
            $perPage = 20;
        }
    
        $search = trim((string) $request->get('search', ''));
    
        $statusSummary = [
            'confirmed' => BarTicket::where('status', 'confirmed')->count(),
            'preparing' => BarTicket::where('status', 'preparing')->count(),
            'ready' => BarTicket::where('status', 'ready')->count(),
            'served' => BarTicket::where('status', 'served')->count(),
        ];
    
        $q = BarTicket::query()
            ->with([
                'orderItem.order.table',
                'orderItem.order.waiter',
                'orderItem.menuItem',
            ])
            ->whereNotIn('status', ['pending', 'served'])
            ->orderByDesc('id');
    
        if ($request->filled('status')) {
            $status = $request->status;
    
            if (!in_array($status, ['pending', 'served'], true)) {
                $q->where('status', $status);
            }
        }
    
        // 🔎 Live search by order number OR waiter name
        if ($search !== '') {
            $q->where(function ($query) use ($search) {
                $query->whereHas('orderItem.order', function ($orderQuery) use ($search) {
                    $orderQuery->where('order_number', 'like', "%{$search}%");
                })
                ->orWhereHas('orderItem.order.waiter', function ($waiterQuery) use ($search) {
                    $waiterQuery->where('name', 'like', "%{$search}%");
                });
            });
        }
    
        $rows = $q->paginate($perPage);
    
        $data = $rows->getCollection()->transform(function ($ticket) {
    
            $menuItem = $ticket->orderItem?->menuItem;
    
            return [
                'bar_ticket_id' => $ticket->id,
                'ticket_status' => $ticket->status,
    
                'order_id' => $ticket->orderItem?->order?->id,
                'order_number' => $ticket->orderItem?->order?->order_number,
                'order_type' => $ticket->orderItem?->order?->order_type,
    
                'order_item_id' => $ticket->orderItem?->id,
                'item_name' => $menuItem?->name,
                'image_path' => $menuItem?->image_path,
    
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
            'status_summary' => $statusSummary,
        ]);
    }

 public function accept(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = BarTicket::lockForUpdate()
                ->with('orderItem.order')
                ->findOrFail($id);

            $this->authorize('accept', $ticket);

            if (! in_array($ticket->status, ['pending', 'confirmed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket is not pending or confirmed',
                ], 422);
            }

            $ticket->update([
                'status' => 'preparing',
                'barman_id' => $request->user()->id,
                'started_at' => now(),
            ]);

            $ticket->orderItem->update([
                'item_status' => 'preparing',
                'started_at' => now(),
            ]);

            $order = $ticket->orderItem->order;
            OrderStatusService::recalc($order->id);

            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'BarTicket',
                $ticket->id,
                'bar_ticket_accepted',
                null,
                $ticket->toArray(),
                'Bar ticket accepted.'
            );

            return response()->json([
                'success' => true,
                'data' => $ticket->fresh()->load('orderItem.order'),
            ]);
        });
    }

    public function ready(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $ticket = BarTicket::lockForUpdate()
                ->with('orderItem.order')
                ->findOrFail($id);

            $this->authorize('ready', $ticket);

            if (! in_array($ticket->status, ['preparing', 'confirmed', 'delayed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket not preparing',
                ], 422);
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
            OrderStatusService::recalc($order->id);

            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Bar item ready',
                "Bar items for order {$order->order_number} are ready.",
                [
                    'kind' => 'bar_ready',
                    'order_id' => $order->id,
                    'ticket_id' => $ticket->id,
                    'order_number' => $order->order_number,
                ]
            );

            $this->auditLogger->log(
                $request,
                $request->user()?->id,
                'BarTicket',
                $ticket->id,
                'bar_ticket_ready',
                null,
                $ticket->toArray(),
                'Bar ticket marked ready.'
            );

            return response()->json([
                'success' => true,
                'data' => $ticket->fresh()->load('orderItem.order'),
            ]);
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
                    'message' => 'Ticket must be ready before serving.',
                ], 422);
            }

            $ticket->update([
                'status' => 'served',
            ]);

            $ticket->orderItem->update([
                'item_status' => 'served',
                'served_at' => now(),
            ]);

            $order = $ticket->orderItem->order;
            OrderStatusService::recalc($order->id);

            $this->notificationService->notifyUser(
                $order->waiter_id,
                'Bar item served',
                "Bar items for order {$order->order_number} have been served.",
                [
                    'kind' => 'bar_served',
                    'order_id' => $order->id,
                    'ticket_id' => $ticket->id,
                    'order_number' => $order->order_number,
                ]
            );

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
                'data' => $ticket->fresh()->load('orderItem.order'),
            ]);
        });
    }
}