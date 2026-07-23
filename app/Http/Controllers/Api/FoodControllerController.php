<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FoodControllerController extends Controller
{
    public function cashSalesReport(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:all,food,drink'],
            'category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
            'period' => ['nullable', 'in:today,this_week,this_month,this_year,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $period = $validated['period'] ?? 'today';
        $dateColumn = 'orders.ordered_at';

        $baseQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menu_items.category_id')
            ->where('orders.payment_status', 'paid')
            ->where(function ($query) {
                $query->whereNull('orders.payment_type')
                    ->orWhere('orders.payment_type', '<>', 'credit');
            });

        match ($period) {
            'today' => $baseQuery->whereBetween($dateColumn, [now()->startOfDay(), now()->endOfDay()]),
            'this_week' => $baseQuery->whereBetween($dateColumn, [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $baseQuery->whereBetween($dateColumn, [now()->startOfMonth(), now()->endOfMonth()]),
            'this_year' => $baseQuery->whereBetween($dateColumn, [now()->startOfYear(), now()->endOfYear()]),
            'custom' => $baseQuery
                ->when(
                    $validated['date_from'] ?? null,
                    fn ($query, $date) => $query->where($dateColumn, '>=', Carbon::parse($date)->startOfDay())
                )
                ->when(
                    $validated['date_to'] ?? null,
                    fn ($query, $date) => $query->where($dateColumn, '<=', Carbon::parse($date)->endOfDay())
                ),
        };

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $baseQuery->where(function ($query) use ($search) {
                $query->where('menu_items.name', 'like', "%{$search}%")
                    ->orWhere('menu_categories.name', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['type']) && $validated['type'] !== 'all') {
            $baseQuery->where('menu_items.type', $validated['type']);
        }

        if (! empty($validated['category_id'])) {
            $baseQuery->where('menu_items.category_id', $validated['category_id']);
        }

        $summary = [
            'distinct_items' => (int) (clone $baseQuery)
                ->distinct()
                ->count('order_items.menu_item_id'),
            'total_orders' => (int) (clone $baseQuery)
                ->distinct()
                ->count('order_items.order_id'),
            'total_quantity' => round((float) (clone $baseQuery)->sum('order_items.quantity'), 2),
            'total_sales' => round((float) (clone $baseQuery)
                ->sum(DB::raw('order_items.quantity * order_items.unit_price')), 2),
        ];

        $rows = (clone $baseQuery)
            ->select([
                'order_items.menu_item_id',
                'menu_items.name as item_name',
                'menu_items.type',
                'menu_categories.name as category_name',
            ])
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as total_orders')
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->selectRaw('SUM(order_items.quantity * order_items.unit_price) as total_sales')
            ->groupBy(
                'order_items.menu_item_id',
                'menu_items.name',
                'menu_items.type',
                'menu_categories.name'
            )
            ->orderByDesc('total_sales')
            ->get()
            ->map(function ($row) {
                $quantity = (float) $row->total_quantity;
                $sales = (float) $row->total_sales;

                return [
                    'menu_item_id' => (int) $row->menu_item_id,
                    'item_name' => $row->item_name,
                    'category_name' => $row->category_name,
                    'type' => $row->type,
                    'total_orders' => (int) $row->total_orders,
                    'total_quantity' => round($quantity, 2),
                    'average_unit_price' => round($quantity > 0 ? $sales / $quantity : 0, 2),
                    'total_sales' => round($sales, 2),
                ];
            });

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $total = $rows->count();

        return response()->json([
            'success' => true,
            'message' => 'Filtered cash sales loaded successfully.',
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'summary' => $summary,
            ],
        ]);
    }

    public function dashboard(Request $request)
    {
        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $statusCounts = Schema::hasTable('purchase_orders')
            ? PurchaseOrder::query()
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
            : collect();

        $pendingValidation = (int) ($statusCounts['submitted'] ?? 0);
        $validated = (int) ($statusCounts['food_validated'] ?? 0);
        $validationRejected = (int) ($statusCounts['validation_rejected'] ?? 0);
        $approved = (int) ($statusCounts['approved'] ?? 0);
        $received = (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['partially_received'] ?? 0));

        $pendingValue = $this->purchaseSum(['submitted']);
        $monthlyValidatedValue = $this->purchaseSum(['food_validated'], $monthStart);
        $monthlyApprovedValue = $this->purchaseSum(['approved', 'partially_received', 'completed'], $monthStart);

        $todayValidated = Schema::hasTable('purchase_orders')
            ? PurchaseOrder::where('status', 'food_validated')->where('updated_at', '>=', $today)->count()
            : 0;

        $weekRejected = Schema::hasTable('purchase_orders')
            ? PurchaseOrder::where('status', 'validation_rejected')->where('updated_at', '>=', $weekStart)->count()
            : 0;

        $lowStockCount = $this->inventoryHas('current_stock', 'minimum_quantity')
            ? InventoryItem::whereColumn('current_stock', '<=', 'minimum_quantity')->count()
            : 0;

        $outOfStockCount = $this->inventoryHas('current_stock')
            ? InventoryItem::where('current_stock', '<=', 0)->count()
            : 0;

        $recentValidationRequests = Schema::hasTable('purchase_orders')
            ? PurchaseOrder::with(['supplier:id,name', 'items.inventoryItem:id,name,base_unit'])
                ->whereIn('status', ['submitted', 'food_validated', 'validation_rejected'])
                ->latest('updated_at')
                ->limit(8)
                ->get()
                ->map(fn (PurchaseOrder $order) => [
                    'id' => $order->id,
                    'po_number' => $order->po_number,
                    'supplier' => $order->supplier?->name ?? 'Unknown supplier',
                    'items_count' => $order->items->count(),
                    'amount' => (float) ($order->total ?? 0),
                    'status' => $order->status,
                    'created_at' => optional($order->created_at)->toDateTimeString(),
                    'updated_at' => optional($order->updated_at)->toDateTimeString(),
                ])
                ->values()
            : collect();

        $validationTrend = $this->buildTrend();

        $lowStockItems = $this->inventoryHas('current_stock', 'minimum_quantity')
            ? InventoryItem::whereColumn('current_stock', '<=', 'minimum_quantity')
                ->orderBy('current_stock')
                ->limit(8)
                ->get(['id', 'name', 'base_unit', 'current_stock', 'minimum_quantity', 'average_purchase_price'])
            : collect();

        $recentTransactions = Schema::hasTable('inventory_transactions')
            ? InventoryTransaction::with('inventoryItem:id,name,base_unit')->latest('id')->limit(8)->get()
            : collect();

        $kitchenPending = $this->ticketCount('kitchen_tickets');
        $barPending = $this->ticketCount('bar_tickets');
        $recipeIssueCount = $this->recipeIssueCount();

        return response()->json([
            'success' => true,
            'message' => 'F&B Controller dashboard fetched successfully.',
            'data' => [
                'kpis' => [
                    'pending_validation' => $pendingValidation,
                    'validated_requests' => $validated,
                    'validation_rejected' => $validationRejected,
                    'approved_requests' => $approved,
                    'received_requests' => $received,
                    'pending_value' => round($pendingValue, 2),
                    'monthly_validated_value' => round($monthlyValidatedValue, 2),
                    'monthly_approved_value' => round($monthlyApprovedValue, 2),
                    'today_validated' => $todayValidated,
                    'week_rejected' => $weekRejected,
                    'low_stock_items' => $lowStockCount,
                    'out_of_stock_items' => $outOfStockCount,
                    'recipe_integrity_issues' => $recipeIssueCount,
                    'active_menu_items' => Schema::hasTable('menu_items') ? MenuItem::where('is_active', true)->count() : 0,
                    'total_suppliers' => Schema::hasTable('suppliers') ? Supplier::count() : 0,
                    'kitchen_pending' => $kitchenPending,
                    'bar_pending' => $barPending,
                ],
                'status_distribution' => [
                    ['label' => 'Submitted', 'status' => 'submitted', 'value' => $pendingValidation],
                    ['label' => 'Validated', 'status' => 'food_validated', 'value' => $validated],
                    ['label' => 'Approved', 'status' => 'approved', 'value' => $approved],
                    ['label' => 'Received', 'status' => 'received', 'value' => $received],
                    ['label' => 'Rejected', 'status' => 'validation_rejected', 'value' => $validationRejected],
                ],
                'workflow' => [
                    ['label' => 'Submitted', 'value' => $pendingValidation],
                    ['label' => 'F&B Validated', 'value' => $validated],
                    ['label' => 'Manager Approved', 'value' => $approved],
                    ['label' => 'Received', 'value' => $received],
                ],
                'trend' => $validationTrend,
                'recent_validation_requests' => $recentValidationRequests,
                'low_stock_items' => $lowStockItems,
                'recent_inventory_transactions' => $recentTransactions,
                'recipe_integrity' => [
                    'menu_items_without_recipe' => 0,
                    'recipes_without_ingredients' => 0,
                    'recipes_with_missing_inventory_links' => 0,
                    'direct_items_without_link' => $recipeIssueCount,
                ],
                'alerts' => [
                    'pending_validation' => $pendingValidation,
                    'validation_rejected' => $validationRejected,
                    'low_stock_items' => $lowStockCount,
                    'recipe_integrity_issues' => $recipeIssueCount,
                    'kitchen_bar_pending' => $kitchenPending + $barPending,
                ],
            ],
            'meta' => null,
        ]);
    }

    private function purchaseSum(array $statuses, $fromDate = null): float
    {
        if (! Schema::hasTable('purchase_orders') || ! Schema::hasColumn('purchase_orders', 'total')) {
            return 0;
        }

        return (float) PurchaseOrder::query()
            ->whereIn('status', $statuses)
            ->when($fromDate, fn ($query) => $query->where('updated_at', '>=', $fromDate))
            ->sum('total');
    }

    private function buildTrend(): array
    {
        $rows = collect(range(6, 0))->mapWithKeys(function (int $daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();

            return [$date => [
                'day' => Carbon::parse($date)->format('D'),
                'date' => $date,
                'submitted' => 0,
                'validated' => 0,
                'rejected' => 0,
            ]];
        });

        if (! Schema::hasTable('purchase_orders')) {
            return $rows->values()->all();
        }

        PurchaseOrder::selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->where('status', 'submitted')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->each(function ($row) use ($rows) {
                $key = (string) $row->day;
                if ($rows->has($key)) {
                    $item = $rows->get($key);
                    $item['submitted'] = (int) $row->total;
                    $rows->put($key, $item);
                }
            });

        PurchaseOrder::selectRaw('DATE(updated_at) as day, status, COUNT(*) as total')
            ->where('updated_at', '>=', now()->subDays(6)->startOfDay())
            ->whereIn('status', ['food_validated', 'validation_rejected'])
            ->groupByRaw('DATE(updated_at), status')
            ->get()
            ->each(function ($row) use ($rows) {
                $key = (string) $row->day;
                if (! $rows->has($key)) {
                    return;
                }

                $item = $rows->get($key);
                if ($row->status === 'food_validated') {
                    $item['validated'] = (int) $row->total;
                }
                if ($row->status === 'validation_rejected') {
                    $item['rejected'] = (int) $row->total;
                }
                $rows->put($key, $item);
            });

        return $rows->values()->all();
    }

    private function ticketCount(string $table): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
            return 0;
        }

        return (int) DB::table($table)
            ->whereIn('status', ['pending', 'confirmed', 'preparing', 'delayed'])
            ->count();
    }

    private function recipeIssueCount(): int
    {
        if (! Schema::hasTable('menu_items')) {
            return 0;
        }

        $count = 0;

        if (Schema::hasColumn('menu_items', 'inventory_tracking_mode') && Schema::hasTable('recipes')) {
            $count += DB::table('menu_items as mi')
                ->leftJoin('recipes as r', 'r.menu_item_id', '=', 'mi.id')
                ->where('mi.inventory_tracking_mode', 'recipe')
                ->whereNull('r.id')
                ->count();
        }

        if (Schema::hasColumn('menu_items', 'inventory_tracking_mode') && Schema::hasColumn('menu_items', 'direct_inventory_item_id')) {
            $count += DB::table('menu_items')
                ->where('inventory_tracking_mode', 'direct')
                ->whereNull('direct_inventory_item_id')
                ->count();
        }

        return (int) $count;
    }

    private function inventoryHas(string ...$columns): bool
    {
        if (! Schema::hasTable('inventory_items')) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn('inventory_items', $column)) {
                return false;
            }
        }

        return true;
    }
}
