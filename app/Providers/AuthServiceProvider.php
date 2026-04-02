<?php

namespace App\Providers;

use App\Models\BarTicket;
use App\Models\Bill;
use App\Models\CashShift;
use App\Models\DiningTable;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KitchenTicket;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\RefundRequest;
use App\Models\StockReceiving;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\BarTicketPolicy;
use App\Policies\BillPolicy;
use App\Policies\CashShiftPolicy;
use App\Policies\DiningTablePolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\InventoryTransactionPolicy;
use App\Policies\KitchenTicketPolicy;
use App\Policies\MenuCategoryPolicy;
use App\Policies\MenuItemPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\RecipePolicy;
use App\Policies\RefundRequestPolicy;
use App\Policies\RolePolicy;
use App\Policies\StockReceivingPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
        User::class => UserPolicy::class,
        MenuCategory::class => MenuCategoryPolicy::class,
        MenuItem::class => MenuItemPolicy::class,
        DiningTable::class => DiningTablePolicy::class,
        Order::class => OrderPolicy::class,
        Bill::class => BillPolicy::class,
        Payment::class => PaymentPolicy::class,
        RefundRequest::class => RefundRequestPolicy::class,
        CashShift::class => CashShiftPolicy::class,
        InventoryItem::class => InventoryItemPolicy::class,
        InventoryTransaction::class => InventoryTransactionPolicy::class,
        Supplier::class => SupplierPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        StockReceiving::class => StockReceivingPolicy::class,
        Recipe::class => RecipePolicy::class,
        KitchenTicket::class => KitchenTicketPolicy::class,
        BarTicket::class => BarTicketPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::define('dashboard.waiter', fn (User $user) => $user->can('dashboard.waiter'));
        Gate::define('cashier.dashboard', fn (User $user) => $user->can('cashier.dashboard'));
        Gate::define('bar.dashboard', fn (User $user) => $user->can('bar.dashboard'));
        Gate::define('kitchen.dashboard', fn (User $user) => $user->can('kitchen.dashboard'));
        Gate::define('food-controller.dashboard', fn (User $user) => $user->can('food-controller.dashboard'));
        Gate::define('finance.dashboard', fn (User $user) => $user->can('finance.dashboard'));
        Gate::define('manager.dashboard', fn (User $user) => $user->can('manager.dashboard'));
        Gate::define('general.dashboard', fn (User $user) => $user->can('general.dashboard'));
    }
}
