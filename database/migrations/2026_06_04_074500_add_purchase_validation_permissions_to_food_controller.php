<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'purchases.read',
            'purchase_orders.read',
            'purchases.validate',
            'purchase_orders.validate',
            'recipes.integrity.read',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $role = Role::where('name', 'F&B Controller')->where('guard_name', 'sanctum')->first();

        if ($role) {
            $role->givePermissionTo([
                'purchases.read',
                'purchase_orders.read',
                'purchases.validate',
                'purchase_orders.validate',
                'recipes.integrity.read',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', 'F&B Controller')->where('guard_name', 'sanctum')->first();

        if ($role) {
            $role->revokePermissionTo([
                'purchases.validate',
                'purchase_orders.validate',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
