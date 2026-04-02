<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function lowStock(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = DB::table('inventory_items')
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->orderBy('quantity')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function reorderSuggestions(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = DB::table('inventory_items')
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->select('*', DB::raw('GREATEST(reorder_level - quantity, 0) as suggested_qty'))
            ->orderBy('suggested_qty', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function recipeIntegrity(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $recipes = DB::table('menu_items as mi')
            ->leftJoin('recipes as r', 'r.menu_item_id', '=', 'mi.id')
            ->leftJoin('recipe_items as ri', 'ri.recipe_id', '=', 'r.id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'ri.inventory_item_id')
            ->selectRaw('mi.id as menu_item_id, mi.name as menu_item_name, mi.type as menu_item_type, r.id as recipe_id, COUNT(ri.id) as ingredient_count, SUM(CASE WHEN ii.id IS NULL THEN 1 ELSE 0 END) as missing_inventory_links')
            ->groupBy('mi.id', 'mi.name', 'mi.type', 'r.id')
            ->orderBy('mi.name')
            ->get();

        $summary = [
            'menu_items_without_recipe' => $recipes->whereNull('recipe_id')->count(),
            'recipes_without_ingredients' => $recipes->whereNotNull('recipe_id')->where('ingredient_count', 0)->count(),
            'recipes_with_missing_inventory_links' => $recipes->where('missing_inventory_links', '>', 0)->count(),
        ];

        return response()->json(['success' => true, 'data' => compact('summary', 'recipes')]);
    }

    public function stockValuation(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = DB::table('inventory_items')
            ->selectRaw('id, name, sku, unit, quantity, unit_cost, ROUND(quantity * unit_cost, 2) as stock_value')
            ->orderByDesc('stock_value')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $rows,
                'total_value' => round((float) $rows->sum('stock_value'), 2),
            ],
        ]);
    }
}
