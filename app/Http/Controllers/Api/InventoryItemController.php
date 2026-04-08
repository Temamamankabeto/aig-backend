<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryItem::class);

        $q = InventoryItem::query()->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->search);
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('unit', 'like', "%{$search}%");
            });
        }

        if ($request->filled('unit')) {
            $q->where('unit', $request->unit);
        }

        if ($request->filled('low_stock')) {
            $lowStock = filter_var($request->low_stock, FILTER_VALIDATE_BOOLEAN);
            if ($lowStock) {
                $q->whereColumn('current_stock', '<=', 'minimum_quantity');
            }
        }

        $paginatedItems = $q->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $paginatedItems->items(),
            'meta' => [
                'current_page' => $paginatedItems->currentPage(),
                'per_page'     => $paginatedItems->perPage(),
                'total'        => $paginatedItems->total(),
                'last_page'    => $paginatedItems->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $row = InventoryItem::with(['transactions' => function ($query) {
            $query->latest('id');
        }])->findOrFail($id);

        $this->authorize('view', $row);

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', InventoryItem::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'minimum_quantity' => 'nullable|numeric|min:0',
            'current_stock' => 'nullable|numeric|min:0',
            'average_purchase_price' => 'nullable|numeric|min:0',
        ]);

        $data['minimum_quantity'] = $data['minimum_quantity'] ?? 0;
        $data['current_stock'] = $data['current_stock'] ?? 0;

        $row = InventoryItem::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Inventory item created successfully',
            'data' => $row,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $row = InventoryItem::findOrFail($id);
        $this->authorize('update', $row);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'unit' => 'sometimes|required|string|max:50',
            'minimum_quantity' => 'sometimes|nullable|numeric|min:0',
            'current_stock' => 'sometimes|nullable|numeric|min:0',
            'average_purchase_price' => 'sometimes|nullable|numeric|min:0',
        ]);

        $row->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Inventory item updated successfully',
            'data' => $row->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $row = InventoryItem::findOrFail($id);
        $this->authorize('delete', $row);

        $row->delete();

        return response()->json([
            'success' => true,
            'message' => 'Inventory item deleted successfully',
        ]);
    }

    public function trashed(Request $request)
    {
        $this->authorize('viewAny', InventoryItem::class);

        $q = InventoryItem::onlyTrashed()->orderBy('deleted_at', 'desc');

        if ($request->filled('search')) {
            $search = trim($request->search);
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('unit', 'like', "%{$search}%");
            });
        }

        if ($request->filled('unit')) {
            $q->where('unit', $request->unit);
        }

        $paginatedItems = $q->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $paginatedItems->items(),
            'meta' => [
                'current_page' => $paginatedItems->currentPage(),
                'per_page'     => $paginatedItems->perPage(),
                'total'        => $paginatedItems->total(),
                'last_page'    => $paginatedItems->lastPage(),
            ],
        ]);
    }

    public function restore($id)
    {
        $row = InventoryItem::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $row);

        $row->restore();

        return response()->json([
            'success' => true,
            'message' => 'Inventory item restored successfully',
            'data' => $row->fresh(),
        ]);
    }

    public function forceDelete($id)
    {
        $row = InventoryItem::onlyTrashed()->findOrFail($id);
        $this->authorize('delete', $row);

        $row->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Inventory item permanently deleted',
        ]);
    }
}