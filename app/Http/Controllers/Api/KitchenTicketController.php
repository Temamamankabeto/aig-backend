<?php

namespace App\Http\Controllers;

use App\Models\KitchenTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenTicketController extends Controller
{
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

            if (! in_array($ticket->status, ['pending', 'confirmed'], true)) {
                return response()->json(['success' => false, 'message' => 'Ticket is not pending or confirmed'], 422);
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

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function ready($id)
    {
        return DB::transaction(function () use ($id) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem.order')->findOrFail($id);

            if ($ticket->status !== 'preparing') {
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

            return response()->json(['success' => true, 'data' => $ticket->fresh()->load('orderItem.order')]);
        });
    }

    public function delay(Request $request, $id)
    {
        $data = $request->validate([
            'delay_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem')->findOrFail($id);

            $ticket->update([
                'status' => 'delayed',
                'delay_reason' => $data['delay_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'delayed']);

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $ticket = KitchenTicket::lockForUpdate()->with('orderItem')->findOrFail($id);

            $ticket->update([
                'status' => 'rejected',
                'rejection_reason' => $data['rejection_reason'],
            ]);

            $ticket->orderItem->update(['item_status' => 'rejected']);

            return response()->json(['success' => true, 'data' => $ticket]);
        });
    }
}