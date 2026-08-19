<?php

use App\Support\RoleNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $guard = 'sanctum';

        DB::transaction(function () use ($tables, $guard): void {
            $role = DB::table($tables['roles'])
                ->where('name', RoleNames::STORE_KEEPER)
                ->where('guard_name', $guard)
                ->first();

            if (! $role) {
                return;
            }

            foreach (['inventory.low_stock.read', 'inventory.alerts.read'] as $permissionName) {
                $permissionId = DB::table($tables['permissions'])
                    ->where('name', $permissionName)
                    ->where('guard_name', $guard)
                    ->value('id');

                if (! $permissionId) {
                    $permissionId = DB::table($tables['permissions'])->insertGetId([
                        'name' => $permissionName,
                        'guard_name' => $guard,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $role->id,
                ]);
            }
        });
    }

    public function down(): void
    {
        $tables = config('permission.table_names');
        $guard = 'sanctum';
        $roleId = DB::table($tables['roles'])
            ->where('name', RoleNames::STORE_KEEPER)
            ->where('guard_name', $guard)
            ->value('id');

        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table($tables['permissions'])
            ->where('guard_name', $guard)
            ->whereIn('name', ['inventory.low_stock.read', 'inventory.alerts.read'])
            ->pluck('id');

        DB::table($tables['role_has_permissions'])
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
