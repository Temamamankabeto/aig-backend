<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
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

            'users.read', 'users.create', 'users.update', 'users.disable', 'users.delete',
            'roles.read', 'roles.assign',
            'permissions.read',
            'audit.read',
            'settings.update',
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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'sanctum',
            ]);
        }

        $roleMap = [
            'Customer' => [
                'auth.me',
                'menu.read',
                'orders.create',
                'orders.read',
                'orders.track',
            ],

            'Waiter' => [
                'auth.me',
                'menu.read',

                'tables.read',
                'tables.assign',
                'tables.transfer',

                'orders.create',
                'orders.read',
                'orders.update',
                'orders.track',

                'order_items.add',
                'order_items.cancel',

                // Waiter can only read credit accounts if credit selection is needed,
                // but should not create/update/block credit accounts.
                'credit.accounts.read',
                'credit.orders.create',

                'package.orders.read',
            ],

            'Cashier' => [
                'auth.me',

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
            ],

            'Kitchen Staff' => [
                'auth.me',
                'menu.read',

                'kitchen.queue.read',
                'kitchen.queue.update',

                'package.orders.read',
                'package.orders.prepare',
            ],

            'Barman' => [
                'auth.me',
                'menu.read',

                'bar.queue.read',
                'bar.queue.update',

                'package.orders.read',
                'package.orders.prepare',
            ],

            'Purchaser' => [
                'auth.me',

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

            'Store Keeper' => [
                'auth.me',

                'inventory.read',
                'inventory.items.read',
                'inventory.adjust',
                'inventory.adjustments.create',
                'inventory.waste.create',
                'inventory.movements.read',
                'inventory.batches.read',

                'stock.receive',

                'purchase_orders.read',
                'purchase_orders.receive',

                // FIXED: this must be inside Store Keeper array
                'package.orders.read',
            ],

            'F&B Controller' => [
                'auth.me',

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

            'Manager' => [
                'auth.me',

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
            ],

            'Finance' => [
                'auth.me',

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
            'name' => 'General Admin',
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

        if (!$admin->hasRole('General Admin')) {
            $admin->assignRole('General Admin');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}