<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockReceiving;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function lowStock(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = InventoryItem::with('batches')
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->orderBy('current_stock')
            ->get()
            ->map(function ($item) {
                $current = (float) $item->current_stock;
                $minimum = (float) $item->minimum_quantity;

                $item->low_stock_level = ($minimum > 0 && $current <= ($minimum * 0.5)) ? 'critical' : 'warning';
                $item->shortage = max($minimum - $current, 0);
                $item->shortage_percent = $minimum > 0 ? round(($item->shortage / $minimum) * 100, 2) : 0;

                return $item;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'critical' => $rows->where('low_stock_level', 'critical')->count(),
                'warning' => $rows->where('low_stock_level', 'warning')->count(),
            ],
        ]);
    }

    public function reorderSuggestions(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $rows = InventoryItem::with('batches')
            ->get()
            ->map(function ($item) {
                $item->suggested_qty = number_format(max((float) $item->minimum_quantity - (float) $item->available_stock, 0), 3, '.', '');
                return $item;
            })
            ->filter(fn ($item) => (float) $item->suggested_qty > 0)
            ->sortByDesc('suggested_qty')
            ->values();

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
            ->selectRaw('id, name, sku, base_unit, minimum_quantity, current_stock, average_purchase_price, ROUND(current_stock * average_purchase_price, 2) as stock_value')
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

    public function stockStatusSummary(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $items = InventoryItem::with('batches')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'in_stock' => $items->where('stock_status', 'in_stock')->count(),
                    'low_stock' => $items->where('stock_status', 'low_stock')->count(),
                    'out_of_stock' => $items->where('stock_status', 'out_of_stock')->count(),
                    'expired' => $items->where('stock_status', 'expired')->count(),
                ],
                'items' => $items,
            ],
        ]);
    }

    public function expiredItems(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $items = InventoryItem::with('batches')
            ->get()
            ->filter(fn ($item) => (float) $item->expired_stock > 0)
            ->values();

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function receivingHistory(Request $request)
    {
        if (! $request->user()?->can('inventory.read')) {
            abort(403);
        }

        $q = StockReceiving::query()->with(['purchaseOrder.supplier', 'items.inventoryItem', 'receiver'])->latest('id');

        if ($request->filled('purchase_order_id')) {
            $q->where('purchase_order_id', $request->integer('purchase_order_id'));
        }

        if ($request->filled('supplier_id')) {
            $supplierId = $request->integer('supplier_id');
            $q->whereHas('purchaseOrder', fn ($sub) => $sub->where('supplier_id', $supplierId));
        }

        if ($request->filled('inventory_item_id')) {
            $inventoryItemId = $request->integer('inventory_item_id');
            $q->whereHas('items', fn ($sub) => $sub->where('inventory_item_id', $inventoryItemId));
        }

        return response()->json(['success' => true, 'data' => $q->paginate((int) $request->get('per_page', 20))]);
    }
}
