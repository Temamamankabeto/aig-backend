<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Support\RoleNames;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'auth.me',
            'general.dashboard',
            'manager.dashboard',
            'food-controller.dashboard',
            'finance.dashboard',
            'dashboard.waiter',
            'cashier.dashboard',
            'kitchen.dashboard',
            'bar.dashboard',

            'users.read', 'users.create', 'users.update', 'users.disable', 'users.delete',
            'roles.read', 'roles.assign',
            'permissions.read',
            'audit.read',
            'settings.update',

            'menu.read', 'menu.create', 'menu.update', 'menu.disable',

            'tables.read', 'tables.create', 'tables.update', 'tables.assign', 'tables.transfer',

            'orders.read', 'orders.create', 'orders.update', 'orders.cancel', 'orders.track',
            'order_items.add', 'order_items.cancel',

            'kitchen.queue.read', 'kitchen.queue.update',
            'bar.queue.read', 'bar.queue.update',

            'bills.read', 'bills.create', 'bills.discount.request', 'bills.discount.approve',
            'bills.split', 'bills.merge',

            'payments.read', 'payments.create', 'payments.refund.request', 'payments.refund.approve',
            'cash_shift.open', 'cash_shift.close', 'cash_shift.read',

            'inventory.read', 'inventory.create', 'inventory.update', 'inventory.adjust',
            'inventory.override', 'inventory.destroy', 'inventory.alerts.read',

            'inventory.items.read', 'inventory.items.create', 'inventory.items.update', 'inventory.items.delete',

            'inventory.adjustments.create',
            'inventory.waste.create',
            'inventory.movements.read',
            'inventory.batches.read',

            'inventory.low_stock.read',
            'inventory.valuation.read',

            'recipes.read', 'recipes.create', 'recipes.update', 'recipes.integrity.read',

            'suppliers.read', 'suppliers.create', 'suppliers.update',

            'purchases.read', 'purchases.create', 'purchases.approve',

            'purchase_orders.read',
            'purchase_orders.create',
            'purchase_orders.submit',
            'purchase_orders.approve',
            'purchase_orders.receive',

            'purchase_requests.create',
            'purchase_requests.approve',

            'stock.receive',
            'stock_receiving.approve',

            'reports.sales.read',
            'reports.staff.read',
            'reports.inventory.read',
            'reports.financial.read',
            'reports.export',

            // Credit
            'credit.accounts.read',
            'credit.accounts.create',
            'credit.accounts.update',
            'credit.accounts.block',

            'credit.orders.read',
            'credit.orders.create',
            'credit.orders.approve',
            'credit.orders.override',
            'credit.orders.settle',

            'credit.reports.read',

            // Packages / Catering
            'packages.read',
            'packages.create',
            'packages.update',
            'packages.delete',

            'package.orders.read',
            'package.orders.create',
            'package.orders.update',
            'package.orders.approve',
            'package.orders.schedule',
            'package.orders.prepare',
            'package.orders.deliver',
            'package.orders.complete',
            'package.orders.cancel',
            'package.orders.settle',
            'package.orders.service',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        $roleMap = [
            RoleNames::CUSTOMER => [
                'auth.me',
                'menu.read',
                'orders.create',
                'orders.read',
                'orders.track',
            ],

            RoleNames::WAITER => [
                'auth.me',
                'dashboard.waiter',
                'menu.read',

                'tables.read',
                'tables.assign',
                'tables.transfer',

                'orders.create',
                'orders.read',
                'orders.update',
                'orders.cancel',
                'orders.track',

                'order_items.add',
                'order_items.cancel',

                // Waiter can only read credit accounts if credit selection is needed,
                // but should not create/update/block credit accounts.
                'credit.accounts.read',
                'credit.orders.create',

                'package.orders.read',
            ],

            RoleNames::CASHIER => [
                'auth.me',
                'cashier.dashboard',

                'users.read', // REQUIRED FOR /cashier/waiters-lite

                'orders.read',
                'orders.create',
                'orders.track',

                'bills.read',
                'bills.create',
                'bills.split',
                'bills.merge',
                'bills.discount.request',

                'payments.read',
                'payments.create',
                'payments.refund.request',

                'cash_shift.open',
                'cash_shift.close',
                'cash_shift.read',

                // Credit payment/settlement
                'credit.accounts.read',
                'credit.orders.read',
                'credit.orders.create',
                'credit.orders.settle',

                // Package payment/settlement
                'package.orders.read',
                'package.orders.settle',
                'reports.sales.read',
            ],

            RoleNames::KITCHEN_STAFF => [
                'auth.me',
                'kitchen.dashboard',
                'menu.read',

                'kitchen.queue.read',
                'kitchen.queue.update',

                'package.orders.read',
                'package.orders.prepare',
            ],

            RoleNames::BARMAN => [
                'auth.me',
                'bar.dashboard',
                'menu.read',

                'bar.queue.read',
                'bar.queue.update',

                'package.orders.read',
                'package.orders.prepare',
            ],

            RoleNames::PURCHASER => [
                'auth.me',

                'inventory.items.read',

                'suppliers.read',
                'suppliers.create',
                'suppliers.update',

                'purchase_orders.read',
                'purchase_orders.create',
                'purchase_orders.submit',

                'purchase_requests.create',

                'purchases.read',
                'purchases.create',
            ],

            RoleNames::STORE_KEEPER => [
                'auth.me',

                'inventory.read',
                'inventory.items.read',
                'inventory.adjust',
                'inventory.adjustments.create',
                'inventory.waste.create',
                'inventory.movements.read',
                'inventory.batches.read',

                'stock.receive',

                'suppliers.read',

                'purchase_orders.read',
                'purchase_orders.create',
                'purchase_orders.submit',
                'purchase_orders.receive',

                'purchase_requests.create',
                'purchases.read',
                'purchases.create',

                // FIXED: this must be inside Store Keeper array
                'package.orders.read',
            ],

            RoleNames::FOOD_CONTROLLER => [
                'auth.me',
                'food-controller.dashboard',

                'menu.read',
                'menu.create',
                'menu.update',
                'menu.disable',

                'inventory.read',
                'inventory.create',
                'inventory.update',
                'inventory.items.read',
                'inventory.items.create',
                'inventory.items.update',
                'inventory.alerts.read',
                'inventory.low_stock.read',
                'inventory.valuation.read',

                'recipes.read',
                'recipes.create',
                'recipes.update',
                'recipes.integrity.read',

                'stock_receiving.approve',
            ],

            RoleNames::MANAGER => [
                'auth.me',
                'manager.dashboard',

                'tables.read',

                'orders.read',
                'orders.update',
                'orders.cancel',

                'kitchen.queue.read',
                'bar.queue.read',

                'purchase_orders.read',
                'purchase_orders.approve',
                'purchase_requests.approve',

                'inventory.read',
                'inventory.items.read',
                'inventory.low_stock.read',
                'inventory.valuation.read',
                'inventory.movements.read',

                'bills.discount.approve',

                'reports.sales.read',
                'reports.staff.read',
                'reports.inventory.read',
                'reports.export',

                // Credit management
                'credit.accounts.read',
                'credit.accounts.update',
                'credit.accounts.block',
                'credit.orders.read',
                'credit.orders.approve',
                'credit.orders.override',
                'credit.reports.read',

                // Catering / package management
                'packages.read',
                'packages.create',
                'packages.update',
                'packages.delete',

                'package.orders.read',
                'package.orders.create',
                'package.orders.update',
                'package.orders.approve',
                'package.orders.schedule',
                'package.orders.prepare',
                'package.orders.deliver',
                'package.orders.complete',
                'package.orders.cancel',
                'package.orders.settle',
                'package.orders.service',
            ],

            RoleNames::FINANCE => [
                'auth.me',
                'finance.dashboard',

                'payments.read',
                'payments.refund.approve',

                'reports.sales.read',
                'reports.financial.read',
                'reports.inventory.read',
                'reports.export',

                'audit.read',

                // Full credit operation
                'credit.accounts.read',
                'credit.accounts.create',
                'credit.accounts.update',
                'credit.accounts.block',

                'credit.orders.read',
                'credit.orders.approve',
                'credit.orders.settle',

                'credit.reports.read',

                // Package settlement
                'package.orders.read',
                'package.orders.settle',
            ],
        ];

        foreach ($roleMap as $roleName => $permissionsForRole) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'sanctum',
            ]);

            $role->syncPermissions($permissionsForRole);
        }

        $generalAdmin = Role::firstOrCreate([
            'name' => RoleNames::GENERAL_ADMIN,
            'guard_name' => 'sanctum',
        ]);

        $generalAdmin->syncPermissions(Permission::where('guard_name', 'sanctum')->pluck('name')->toArray());

        $admin = User::firstOrCreate(
            ['email' => 'admin@aig.com'],
            [
                'name' => 'General Admin',
                'phone' => '+1234567890',
                'password' => Hash::make('Admin@12345'),
            ]
        );

        if (!$admin->hasRole(RoleNames::GENERAL_ADMIN)) {
            $admin->assignRole(RoleNames::GENERAL_ADMIN);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
