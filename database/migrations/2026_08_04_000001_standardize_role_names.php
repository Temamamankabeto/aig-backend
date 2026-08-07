<?php

use App\Support\RoleNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $rolesTable = $tables['roles'];
        $modelHasRolesTable = $tables['model_has_roles'];
        $roleHasPermissionsTable = $tables['role_has_permissions'];
        $guard = 'sanctum';

        DB::transaction(function () use ($rolesTable, $modelHasRolesTable, $roleHasPermissionsTable, $guard): void {
            foreach (RoleNames::aliases() as $alias => $canonicalName) {
                $aliasRole = DB::table($rolesTable)
                    ->where('guard_name', $guard)
                    ->where('name', $alias)
                    ->first();

                if (!$aliasRole) {
                    continue;
                }

                $canonicalRole = DB::table($rolesTable)
                    ->where('guard_name', $guard)
                    ->where('name', $canonicalName)
                    ->first();

                if ($canonicalRole && (int) $canonicalRole->id === (int) $aliasRole->id) {
                    DB::table($rolesTable)
                        ->where('id', $aliasRole->id)
                        ->update(['name' => $canonicalName, 'updated_at' => now()]);

                    continue;
                }

                if (!$canonicalRole) {
                    DB::table($rolesTable)
                        ->where('id', $aliasRole->id)
                        ->update(['name' => $canonicalName, 'updated_at' => now()]);

                    continue;
                }

                $modelAssignments = DB::table($modelHasRolesTable)
                    ->where('role_id', $aliasRole->id)
                    ->get();

                foreach ($modelAssignments as $assignment) {
                    DB::table($modelHasRolesTable)->insertOrIgnore([
                        'role_id' => $canonicalRole->id,
                        'model_type' => $assignment->model_type,
                        'model_id' => $assignment->model_id,
                    ]);
                }

                $permissionAssignments = DB::table($roleHasPermissionsTable)
                    ->where('role_id', $aliasRole->id)
                    ->get();

                foreach ($permissionAssignments as $assignment) {
                    DB::table($roleHasPermissionsTable)->insertOrIgnore([
                        'permission_id' => $assignment->permission_id,
                        'role_id' => $canonicalRole->id,
                    ]);
                }

                DB::table($modelHasRolesTable)->where('role_id', $aliasRole->id)->delete();
                DB::table($roleHasPermissionsTable)->where('role_id', $aliasRole->id)->delete();
                DB::table($rolesTable)->where('id', $aliasRole->id)->delete();
            }
        });
    }

    public function down(): void
    {
        // Canonicalization is intentionally irreversible because reverting could
        // split existing user assignments and permissions across duplicate roles.
    }
};
