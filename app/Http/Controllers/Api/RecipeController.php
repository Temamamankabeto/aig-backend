<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Recipe::class);
        return response()->json(['success' => true, 'data' => Recipe::query()->with(['menuItem', 'items.inventoryItem'])->orderBy('id', 'desc')->paginate((int) ($request->get('per_page', 20)))]);
    }

    public function show($id)
    {
        $row = Recipe::with(['menuItem', 'items.inventoryItem'])->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function showByMenuItem($menuItemId)
    {
        $this->authorize('viewAny', Recipe::class);
        $row = Recipe::with(['items.inventoryItem'])->where('menu_item_id', $menuItemId)->first();
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Recipe::class);
        $data = $request->validate([
            'menu_item_id' => 'required|exists:menu_items,id|unique:recipes,menu_item_id',
            'note' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        return DB::transaction(function () use ($data) {
            $recipe = Recipe::create(['menu_item_id' => $data['menu_item_id'], 'note' => $data['note'] ?? null]);
            foreach ($data['items'] as $it) {
                RecipeItem::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $it['inventory_item_id'],
                    'quantity' => (float) $it['quantity'],
                    'base_unit' => InventoryItem::find($it['inventory_item_id'])?->base_unit ?? 'pcs',
                ]);
            }
            return response()->json(['success' => true, 'data' => $recipe->load('items.inventoryItem')], 201);
        });
    }

    public function update(Request $request, $id)
    {
        $recipe = Recipe::findOrFail($id);
        $this->authorize('update', $recipe);
        $data = $request->validate([
            'note' => 'nullable|string|max:2000',
            'items' => 'nullable|array|min:1',
            'items.*.inventory_item_id' => 'required_with:items|exists:inventory_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
        ]);

        return DB::transaction(function () use ($recipe, $data) {
            $recipe = Recipe::lockForUpdate()->findOrFail($recipe->id);
            if (array_key_exists('note', $data)) {
                $recipe->note = $data['note'];
                $recipe->save();
            }
            if (!empty($data['items'])) {
                RecipeItem::where('recipe_id', $recipe->id)->delete();
                foreach ($data['items'] as $it) {
                    RecipeItem::create([
                        'recipe_id' => $recipe->id,
                        'inventory_item_id' => $it['inventory_item_id'],
                        'quantity' => (float) $it['quantity'],
                        'base_unit' => InventoryItem::find($it['inventory_item_id'])?->base_unit ?? 'pcs',
                    ]);
                }
            }
            return response()->json(['success' => true, 'data' => $recipe->fresh()->load('items.inventoryItem')]);
        });
    }
}
