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

    if ($request->filled('category')) $q->where('category', $request->category);
    if ($request->filled('is_active')) $q->where('is_active', (bool) $request->is_active);
    if ($request->filled('search')) $q->where('name', 'like', '%' . $request->search . '%');

    // 1. Get the paginator instance
    $paginatedItems = $q->paginate((int) $request->get('per_page', 20));

    // 2. Return the exact structure you requested
    return response()->json([
        'success' => true,
        'data' => $paginatedItems->items(), // This returns the array of items only
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
        $row = InventoryItem::with('transactions')->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', InventoryItem::class);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category' => 'nullable|in:food,beverage,consumable',
            'unit' => 'required|string|max:50',
            'quantity' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        return response()->json(['success' => true, 'data' => InventoryItem::create($data)], 201);
    }

    public function update(Request $request, $id)
    {
        $row = InventoryItem::findOrFail($id);
        $this->authorize('update', $row);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku,' . $row->id,
            'category' => 'sometimes|in:food,beverage,consumable',
            'unit' => 'sometimes|string|max:50',
            'reorder_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $row->update($data);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function destroy($id)
    {
        $row = InventoryItem::findOrFail($id);
        $this->authorize('delete', $row);
        
        $row->delete(); // Soft delete
        
        return response()->json([
            'success' => true,
            'message' => 'Inventory item deleted successfully'
        ]);
    }

    public function forceDelete($id)
    {
        $row = InventoryItem::withTrashed()->findOrFail($id);
        $this->authorize('delete', $row);
        
        $row->forceDelete(); // Permanent delete
        
        return response()->json([
            'success' => true,
            'message' => 'Inventory item permanently deleted'
        ]);
    }

    public function restore($id)
    {
        $row = InventoryItem::withTrashed()->findOrFail($id);
        $this->authorize('restore', $row);
        
        $row->restore();
        
        return response()->json([
            'success' => true,
            'message' => 'Inventory item restored successfully',
            'data' => $row
        ]);
    }

    public function trashed(Request $request)
    {
        $this->authorize('viewAny', InventoryItem::class);
        
        $q = InventoryItem::onlyTrashed()->orderBy('deleted_at', 'desc');
        
        if ($request->filled('category')) $q->where('category', $request->category);
        if ($request->filled('search')) $q->where('name', 'like', '%' . $request->search . '%');
        
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
}
