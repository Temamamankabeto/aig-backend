<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenTicket;
use App\Models\BarTicket;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FoodControllerController extends Controller
{
    public function dashboard(Request $request)
    {
        Gate::authorize('food-controller.dashboard');

        $lowStockCount = InventoryItem::query()
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->count();

        $outOfStockCount = InventoryItem::query()
            ->where('current_stock', '<=', 0)
            ->count();

        $recipeIntegrity = DB::table('menu_items as mi')
            ->leftJoin('recipes as r', 'r.menu_item_id', '=', 'mi.id')
            ->leftJoin('recipe_items as ri', 'ri.recipe_id', '=', 'r.id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'ri.inventory_item_id')
            ->selectRaw('mi.id as menu_item_id, mi.inventory_tracking_mode, mi.direct_inventory_item_id, r.id as recipe_id, COUNT(ri.id) as ingredient_count, SUM(CASE WHEN ii.id IS NULL THEN 1 ELSE 0 END) as missing_inventory_links')
            ->groupBy('mi.id', 'mi.inventory_tracking_mode', 'mi.direct_inventory_item_id', 'r.id')
            ->get();

        $summary = [
            'menu' => [
                'total_items' => MenuItem::count(),
                'food_items' => MenuItem::where('type', 'food')->count(),
                'drink_items' => MenuItem::where('type', 'drink')->count(),
                'available_items' => MenuItem::where('is_active', true)->where('is_available', true)->count(),
                'unavailable_items' => MenuItem::where(function ($q) {
                    $q->where('is_active', false)->orWhere('is_available', false);
                })->count(),
            ],
            'inventory' => [
                'total_items' => InventoryItem::count(),
                'low_stock_items' => $lowStockCount,
                'out_of_stock_items' => $outOfStockCount,
                'stock_value' => round((float) InventoryItem::query()->selectRaw('COALESCE(SUM(current_stock * average_purchase_price), 0) as total')->value('total'), 2),
            ],
            'recipes' => [
                'total_recipes' => Recipe::count(),
                'menu_items_without_recipe' => $recipeIntegrity->where('inventory_tracking_mode', 'recipe')->whereNull('recipe_id')->count(),
                'recipes_without_ingredients' => $recipeIntegrity->where('inventory_tracking_mode', 'recipe')->whereNotNull('recipe_id')->where('ingredient_count', 0)->count(),
                'recipes_with_missing_inventory_links' => $recipeIntegrity->where('inventory_tracking_mode', 'recipe')->where('missing_inventory_links', '>', 0)->count(),
                'direct_items_without_link' => $recipeIntegrity->where('inventory_tracking_mode', 'direct')->whereNull('direct_inventory_item_id')->count(),
            ],
            'procurement' => [
                'suppliers' => Supplier::count(),
                'draft_purchase_orders' => PurchaseOrder::where('status', 'draft')->count(),
                'approved_purchase_orders' => PurchaseOrder::where('status', 'approved')->count(),
                'received_purchase_orders' => PurchaseOrder::where('status', 'received')->count(),
            ],
            'operations' => [
                'kitchen_pending' => KitchenTicket::whereIn('status', ['pending', 'confirmed', 'preparing', 'delayed'])->count(),
                'bar_pending' => BarTicket::whereIn('status', ['pending', 'confirmed', 'preparing', 'delayed'])->count(),
            ],
        ];

        $lowStockItems = InventoryItem::query()
            ->whereColumn('current_stock', '<=', 'minimum_quantity')
            ->orderBy('current_stock')
            ->limit(10)
            ->get(['id', 'name', 'unit', 'current_stock', 'minimum_quantity', 'average_purchase_price']);

        $recentTransactions = InventoryTransaction::query()
            ->with('inventoryItem:id,name,unit')
            ->latest('id')
            ->limit(10)
            ->get();

        $pendingPurchaseOrders = PurchaseOrder::query()
            ->with(['supplier:id,name'])
            ->whereIn('status', ['draft', 'approved'])
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'role' => 'food-controller',
            'message' => 'Food Controller Dashboard',
            'user' => $request->user(),
            'data' => [
                'summary' => $summary,
                'low_stock_items' => $lowStockItems,
                'recent_inventory_transactions' => $recentTransactions,
                'pending_purchase_orders' => $pendingPurchaseOrders,
            ],
        ]);
    }
}
