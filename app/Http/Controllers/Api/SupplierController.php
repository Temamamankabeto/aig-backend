<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);
        $q = Supplier::query()->withCount('purchaseOrders')->withSum('purchaseOrders as purchase_orders_total', 'total')->orderBy('id', 'desc');

        if ($request->filled('is_active')) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
    }

    public function show($id)
    {
        $row = Supplier::with(['purchaseOrders.items.inventoryItem', 'purchaseOrders.receivings.items'])->findOrFail($id);
        $this->authorize('view', $row);

        $poCount = $row->purchaseOrders->count();
        $completed = $row->purchaseOrders->whereIn('status', ['completed', 'received'])->count();
        $cancelled = $row->purchaseOrders->where('status', 'cancelled')->count();
        $receivingCount = $row->purchaseOrders->sum(fn ($po) => $po->receivings->count());
        $suppliedItems = $row->purchaseOrders
            ->flatMap(fn ($po) => $po->items->pluck('inventoryItem.name'))
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'supplier' => $row,
                'performance' => [
                    'purchase_orders_count' => $poCount,
                    'completed_purchase_orders_count' => $completed,
                    'cancelled_purchase_orders_count' => $cancelled,
                    'receiving_records_count' => $receivingCount,
                    'completion_rate' => $poCount > 0 ? round(($completed / $poCount) * 100, 2) : 0,
                    'supplied_items' => $suppliedItems,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Supplier::class);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'tax_id' => 'nullable|string|max:100',
            'credit_days' => 'nullable|integer|min:0',
            'contract_terms' => 'nullable|string|max:5000',
            'delivery_performance_notes' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        return response()->json(['success' => true, 'data' => Supplier::create($data)], 201);
    }

    public function update(Request $request, $id)
    {
        $row = Supplier::findOrFail($id);
        $this->authorize('update', $row);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'tax_id' => 'nullable|string|max:100',
            'credit_days' => 'nullable|integer|min:0',
            'contract_terms' => 'nullable|string|max:5000',
            'delivery_performance_notes' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $row->update($data);
        return response()->json(['success' => true, 'data' => $row]);
    }


    public function performance($id)
    {
        $row = Supplier::with(['purchaseOrders.items.inventoryItem', 'purchaseOrders.receivings.items'])->findOrFail($id);
        $this->authorize('view', $row);

        $purchaseOrders = $row->purchaseOrders;
        $poCount = $purchaseOrders->count();
        $completed = $purchaseOrders->whereIn('status', ['completed', 'received'])->count();
        $cancelled = $purchaseOrders->where('status', 'cancelled')->count();
        $approved = $purchaseOrders->whereIn('status', ['approved', 'partially_received', 'completed', 'received'])->count();
        $receivings = $purchaseOrders->flatMap->receivings;
        $receivingCount = $receivings->count();
        $receivedOnTime = $purchaseOrders->filter(function ($po) {
            if (! $po->expected_date || ! $po->received_at) {
                return false;
            }
            return \Carbon\Carbon::parse($po->received_at)->toDateString() <= \Carbon\Carbon::parse($po->expected_date)->toDateString();
        })->count();

        $orderedLines = $purchaseOrders->flatMap->items;
        $orderedQty = (float) $orderedLines->sum(fn ($item) => (float) $item->quantity);
        $receivedQty = (float) $orderedLines->sum(fn ($item) => (float) ($item->received_quantity ?? 0));

        $suppliedItems = $orderedLines->map(fn ($item) => [
            'inventory_item_id' => $item->inventory_item_id,
            'name' => $item->inventoryItem?->name,
        ])->filter(fn ($row) => $row['inventory_item_id'] && $row['name'])->unique('inventory_item_id')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'supplier' => $row,
                'metrics' => [
                    'purchase_orders_count' => $poCount,
                    'approved_purchase_orders_count' => $approved,
                    'completed_purchase_orders_count' => $completed,
                    'cancelled_purchase_orders_count' => $cancelled,
                    'receiving_records_count' => $receivingCount,
                    'ordered_quantity_total' => round($orderedQty, 3),
                    'received_quantity_total' => round($receivedQty, 3),
                    'fulfillment_rate' => $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 2) : 0,
                    'completion_rate' => $poCount > 0 ? round(($completed / $poCount) * 100, 2) : 0,
                    'on_time_delivery_rate' => $poCount > 0 ? round(($receivedOnTime / $poCount) * 100, 2) : 0,
                    'purchase_value_total' => round((float) $purchaseOrders->sum('total'), 2),
                    'average_purchase_value' => $poCount > 0 ? round((float) $purchaseOrders->avg('total'), 2) : 0,
                ],
                'supplied_items' => $suppliedItems,
            ],
        ]);
    }

}
