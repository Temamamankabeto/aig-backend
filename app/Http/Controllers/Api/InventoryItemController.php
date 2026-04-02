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

        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
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
            'category' => 'required|in:food,beverage,consumable',
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
}
