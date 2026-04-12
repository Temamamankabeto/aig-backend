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
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->orderBy('current_stock')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function reorderSuggestions(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = DB::table('inventory_items')
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->select('*', DB::raw('GREATEST(minimum_quantity - current_stock, 0) as suggested_qty'))
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
            ->selectRaw('mi.id as menu_item_id, mi.name as menu_item_name, mi.type as menu_item_type, mi.inventory_tracking_mode, mi.direct_inventory_item_id, r.id as recipe_id, COUNT(ri.id) as ingredient_count, SUM(CASE WHEN ii.id IS NULL THEN 1 ELSE 0 END) as missing_inventory_links')
            ->groupBy('mi.id', 'mi.name', 'mi.type', 'mi.inventory_tracking_mode', 'mi.direct_inventory_item_id', 'r.id')
            ->orderBy('mi.name')
            ->get();

        $summary = [
            'menu_items_without_recipe' => $recipes->where('inventory_tracking_mode', 'recipe')->whereNull('recipe_id')->count(),
            'recipes_without_ingredients' => $recipes->where('inventory_tracking_mode', 'recipe')->whereNotNull('recipe_id')->where('ingredient_count', 0)->count(),
            'recipes_with_missing_inventory_links' => $recipes->where('inventory_tracking_mode', 'recipe')->where('missing_inventory_links', '>', 0)->count(),
            'direct_items_without_link' => $recipes->where('inventory_tracking_mode', 'direct')->whereNull('direct_inventory_item_id')->count(),
        ];

        return response()->json(['success' => true, 'data' => compact('summary', 'recipes')]);
    }

    public function stockValuation(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = DB::table('inventory_items')
            ->selectRaw('id, name, unit, minimum_quantity, current_stock, average_purchase_price, ROUND(current_stock * average_purchase_price, 2) as stock_value')
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
