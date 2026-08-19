<?php

use App\Support\RoleNames;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private array $roles = [
        RoleNames::FOOD_CONTROLLER,
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('reports.sales.read');

        Role::query()
            ->whereIn('name', $this->roles)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->where('name', 'reports.sales.read')->first();

        if ($permission) {
            Role::query()
                ->whereIn('name', $this->roles)
                ->get()
                ->each(fn (Role $role) => $role->revokePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
