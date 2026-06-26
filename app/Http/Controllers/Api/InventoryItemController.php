<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryItem::class);
    
        $q = InventoryItem::query()
            ->with(['batches'])
            ->orderBy('id', 'desc');
    
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
    
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('base_unit', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
            });
        }
    
        if ($request->filled('base_unit')) {
            $q->where('base_unit', $request->base_unit);
        }
    
        if ($request->filled('is_active')) {
            $q->where(
                'is_active',
                filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
            );
        }
    
        if ($request->filled('status')) {
    
            switch ($request->status) {
    
                case 'low_stock':
                    $q->whereColumn('current_stock', '<=', 'minimum_quantity');
                    break;
    
                case 'out_of_stock':
                    $q->where('current_stock', '=', 0);
                    break;
    
                case 'in_stock':
                    $q->whereColumn('current_stock', '>', 'minimum_quantity');
                    break;
    
                case 'expired':
                    $q->whereHas('batches', function ($query) {
                        $query->whereDate('expiry_date', '<', now());
                    });
                    break;
            }
        }
    
        $items = $q->paginate((int) $request->get('per_page', 10));
    
        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $row = InventoryItem::with([
            'transactions' => fn ($query) => $query->latest('id'),
            'batches' => fn ($query) => $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id'),
            'recipeItems.recipe.menuItem',
        ])->findOrFail($id);

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
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'base_unit' => 'required|in:kg,L,pcs',
            'minimum_quantity' => 'nullable|numeric|min:0',
            'current_stock' => 'nullable|numeric|min:0',
            'average_purchase_price' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $data['minimum_quantity'] = $data['minimum_quantity'] ?? 0;
        $data['current_stock'] = $data['current_stock'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        return DB::transaction(function () use ($data) {

            $row = InventoryItem::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Inventory item created successfully',
                'data' => $row,
            ], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $row = InventoryItem::findOrFail($id);
        $this->authorize('update', $row);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|nullable|string|max:100|unique:inventory_items,sku,' . $row->id,
            'base_unit' => 'sometimes|required|in:kg,L,pcs',
            'minimum_quantity' => 'sometimes|nullable|numeric|min:0',
            'current_stock' => 'sometimes|nullable|numeric|min:0',
            'average_purchase_price' => 'sometimes|nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        return DB::transaction(function () use ($row, $data) {

            $row->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Inventory item updated successfully',
                'data' => $row->fresh()->load('batches'),
            ]);
        });
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
            $search = trim((string) $request->search);
            $q->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('base_unit', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('base_unit')) {
            $q->where('base_unit', $request->base_unit);
        }

        $items = $q->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
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
        $this->authorize('forceDelete', $row);

        $row->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Inventory item permanently deleted',
        ]);
    }
}
