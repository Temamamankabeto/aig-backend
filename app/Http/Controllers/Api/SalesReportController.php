<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    public function cashiers(): JsonResponse
    {
        $cashiers = User::query()
            ->select(['id', 'name', 'email', 'phone'])
            ->where('is_active', true)
            ->role(RoleNames::CASHIER)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Cashiers loaded successfully.',
            'data' => $cashiers,
            'meta' => [
                'total' => $cashiers->count(),
            ],
        ]);
    }

    public function soldItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:all,food,drink'],
            'payment_type' => ['nullable', 'in:all,cash,credit'],
            'category_id' => ['nullable', 'integer', 'exists:menu_categories,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'period' => ['nullable', 'in:today,this_week,this_month,this_year,custom'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $baseQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menu_items.category_id')
            ->whereIn('orders.payment_status', ['paid', 'credit_pending'])
            ->whereNotIn('orders.status', ['cancelled', 'void', 'rejected']);

        $this->applyFilters($baseQuery, $validated);

        $summary = [
            'distinct_items' => (int) (clone $baseQuery)
                ->distinct()
                ->count('order_items.menu_item_id'),
            'total_orders' => (int) (clone $baseQuery)
                ->distinct()
                ->count('order_items.order_id'),
            'total_quantity' => round((float) (clone $baseQuery)->sum('order_items.quantity'), 2),
            'cash_sales' => round((float) (clone $baseQuery)->sum(DB::raw(
                "CASE WHEN COALESCE(orders.payment_type, 'cash') <> 'credit' "
                .'THEN order_items.quantity * order_items.unit_price ELSE 0 END'
            )), 2),
            'credit_sales' => round((float) (clone $baseQuery)->sum(DB::raw(
                "CASE WHEN orders.payment_type = 'credit' "
                .'THEN order_items.quantity * order_items.unit_price ELSE 0 END'
            )), 2),
            'total_sales' => round((float) (clone $baseQuery)->sum(DB::raw(
                'order_items.quantity * order_items.unit_price'
            )), 2),
        ];

        $paymentMethodsByItem = (clone $baseQuery)
            ->select([
                'order_items.menu_item_id',
                'orders.payment_type',
                'orders.payment_method',
            ])
            ->distinct()
            ->get()
            ->groupBy('menu_item_id')
            ->map(function ($methods): string {
                return $methods
                    ->map(function (object $method): string {
                        if ($method->payment_type === 'credit') {
                            return 'credit';
                        }

                        return strtolower((string) ($method->payment_method ?: $method->payment_type ?: 'cash'));
                    })
                    ->filter()
                    ->unique()
                    ->sort()
                    ->implode(', ');
            });

        $rows = (clone $baseQuery)
            ->select([
                'order_items.menu_item_id',
                'menu_items.name as item_name',
                'menu_items.type',
                'menu_categories.name as category_name',
            ])
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as total_orders')
            ->selectRaw('SUM(order_items.quantity) as total_quantity')
            ->selectRaw(
                "SUM(CASE WHEN COALESCE(orders.payment_type, 'cash') <> 'credit' "
                .'THEN order_items.quantity * order_items.unit_price ELSE 0 END) as cash_sales'
            )
            ->selectRaw(
                "SUM(CASE WHEN orders.payment_type = 'credit' "
                .'THEN order_items.quantity * order_items.unit_price ELSE 0 END) as credit_sales'
            )
            ->selectRaw('SUM(order_items.quantity * order_items.unit_price) as total_sales')
            ->groupBy(
                'order_items.menu_item_id',
                'menu_items.name',
                'menu_items.type',
                'menu_categories.name'
            )
            ->orderBy('menu_categories.name')
            ->orderBy('menu_items.name')
            ->get()
            ->map(function (object $row) use ($paymentMethodsByItem): array {
                $quantity = (float) $row->total_quantity;
                $totalSales = (float) $row->total_sales;

                return [
                    'menu_item_id' => (int) $row->menu_item_id,
                    'item_name' => $row->item_name,
                    'category_name' => $row->category_name,
                    'type' => $row->type,
                    'total_orders' => (int) $row->total_orders,
                    'total_quantity' => round($quantity, 2),
                    'average_unit_price' => round($quantity > 0 ? $totalSales / $quantity : 0, 2),
                    'cash_sales' => round((float) $row->cash_sales, 2),
                    'credit_sales' => round((float) $row->credit_sales, 2),
                    'total_sales' => round($totalSales, 2),
                    'payment_method' => $paymentMethodsByItem->get($row->menu_item_id, 'cash'),
                ];
            });

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return response()->json([
            'success' => true,
            'message' => 'Filtered sold items report loaded successfully.',
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'summary' => $summary,
                'filters' => [
                    'period' => $validated['period'] ?? 'today',
                    'date_from' => $validated['date_from'] ?? null,
                    'date_to' => $validated['date_to'] ?? null,
                    'payment_type' => $validated['payment_type'] ?? 'all',
                    'type' => $validated['type'] ?? 'all',
                    'cashier_id' => $validated['cashier_id'] ?? null,
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $period = $filters['period'] ?? 'today';
        $dateColumn = 'orders.ordered_at';

        match ($period) {
            'today' => $query->whereBetween($dateColumn, [now()->startOfDay(), now()->endOfDay()]),
            'this_week' => $query->whereBetween($dateColumn, [now()->startOfWeek(), now()->endOfWeek()]),
            'this_month' => $query->whereBetween($dateColumn, [now()->startOfMonth(), now()->endOfMonth()]),
            'this_year' => $query->whereBetween($dateColumn, [now()->startOfYear(), now()->endOfYear()]),
            'custom' => $query
                ->when(
                    $filters['date_from'] ?? null,
                    fn (Builder $builder, string $date) => $builder->where(
                        $dateColumn,
                        '>=',
                        Carbon::parse($date)->startOfDay()
                    )
                )
                ->when(
                    $filters['date_to'] ?? null,
                    fn (Builder $builder, string $date) => $builder->where(
                        $dateColumn,
                        '<=',
                        Carbon::parse($date)->endOfDay()
                    )
                ),
        };

        $query->when($filters['search'] ?? null, function (Builder $builder, string $search): void {
            $search = trim($search);
            $builder->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('menu_items.name', 'like', "%{$search}%")
                    ->orWhere('menu_categories.name', 'like', "%{$search}%");
            });
        });

        $query->when(
            ! empty($filters['type']) && $filters['type'] !== 'all',
            fn (Builder $builder) => $builder->where('menu_items.type', $filters['type'])
        );

        $query->when(
            ! empty($filters['category_id']),
            fn (Builder $builder) => $builder->where('menu_items.category_id', $filters['category_id'])
        );

        $query->when(
            ! empty($filters['cashier_id']),
            function (Builder $builder) use ($filters): void {
                $cashierId = (int) $filters['cashier_id'];

                $builder->where(function (Builder $cashierQuery) use ($cashierId): void {
                    $cashierQuery->where('orders.payment_received_by', $cashierId)
                        ->orWhere(function (Builder $creditQuery) use ($cashierId): void {
                            $creditQuery->where('orders.payment_type', 'credit')
                                ->where('orders.created_by', $cashierId);
                        });
                });
            }
        );

        $query->when(
            ($filters['payment_type'] ?? 'all') === 'cash',
            fn (Builder $builder) => $builder->where(function (Builder $paymentQuery): void {
                $paymentQuery->whereNull('orders.payment_type')
                    ->orWhere('orders.payment_type', '<>', 'credit');
            })
        );

        $query->when(
            ($filters['payment_type'] ?? 'all') === 'credit',
            fn (Builder $builder) => $builder->where('orders.payment_type', 'credit')
        );
    }
}
