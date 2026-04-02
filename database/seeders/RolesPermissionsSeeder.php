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
        // Clear cached roles/permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /**
         * ✅ Permissions (module.action naming)
         * Keep them stable. Frontend uses these keys to show/hide UI.
         */
        $permissions = [
            // Auth / profile
            'auth.me',

            // Users / RBAC / Admin
            'users.read', 'users.create', 'users.update', 'users.disable',
            'roles.read', 'roles.assign',
            'permissions.read',
            'audit.read',
            'settings.update',

            // Menu
            'menu.read',
            'menu.create',
            'menu.update',
            'menu.disable',

            // Tables
            'tables.read',
            'tables.create',
            'tables.update',
            'tables.assign',
            'tables.transfer',

            // Orders
            'orders.read',
            'orders.create',
            'orders.update',
            'orders.cancel',
            'orders.track',

            // Order Items (granular)
            'order_items.add',
            'order_items.cancel',

            // Kitchen Queue
            'kitchen.queue.read',
            'kitchen.queue.update',   // accept/start/ready/delay/reject

            // Bar Queue
            'bar.queue.read',
            'bar.queue.update',       // accept/start/ready/delay/reject

            // Billing
            'bills.read',
            'bills.create',
            'bills.discount.request',
            'bills.discount.approve',
            'bills.split',
            'bills.merge',

            // Payments / Cashier
            'payments.read',
            'payments.create',
            'payments.refund.request',
            'payments.refund.approve',
            'cash_shift.open',
            'cash_shift.close',
            'cash_shift.read',

            // Inventory
            'inventory.read',
            'inventory.create',
            'inventory.update',
            'inventory.adjust',
            'inventory.alerts.read',

            // Recipes (F&B)
            'recipes.read',
            'recipes.create',
            'recipes.update',

            // Suppliers / Purchase
            'suppliers.read', 'suppliers.create', 'suppliers.update',
            'purchase_orders.read', 'purchase_orders.create', 'purchase_orders.approve',
            'stock_receiving.approve',

            // Reports
            'reports.sales.read',
            'reports.staff.read',
            'reports.inventory.read',
            'reports.financial.read',
            'reports.export',
        ];

        // Create permissions (idempotent)
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }

        /**
         * ✅ Role → Permission mapping
         */
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
            ],

            'Cashier' => [
                'auth.me',
                'orders.read',
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
                'inventory.adjust',
                'inventory.alerts.read',

                'recipes.read',
                'recipes.create',
                'recipes.update',

                'suppliers.read', 'suppliers.create', 'suppliers.update',
                'purchase_orders.read', 'purchase_orders.create',
                'stock_receiving.approve',
            ],

            'Barman' => [
                'auth.me',
                'bar.queue.read',
                'bar.queue.update',
                'menu.read',
            ],

            'Manager' => [
                'auth.me',

                'tables.read',
                'orders.read',
                'orders.update',
                'orders.cancel',

                'kitchen.queue.read',
                'bar.queue.read',

                'reports.sales.read',
                'reports.staff.read',
                'reports.inventory.read',
                'reports.export',

                'bills.discount.approve',
                'purchase_orders.approve',
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
            ],

            // Admin gets everything
            'General Admin' => Permission::all()->pluck('name')->toArray(),
        ];

        // Create roles + assign permissions
        foreach ($roleMap as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);
            $role->syncPermissions($perms);
        }

        /**
         * ✅ Create default admin user
         * Change email/password after first login.
         */
        $adminEmail = 'admin@aig.com';
        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => 'General Admin',
                'password' => Hash::make('Admin@12345'),
            ]
        );

        if (!$admin->hasRole('General Admin')) {
            $admin->assignRole('General Admin');
        }
    }
}