<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\AssignRolePermissionsRequest;
use App\Http\Requests\Role\IndexPermissionRequest;
use App\Http\Requests\Role\IndexRoleRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(
        protected RoleService $roleService
    ) {}

    public function index(IndexRoleRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = $this->roleService->paginateRoles($request->validated());

        return response()->json(
            $this->roleService->transformPaginatedRoles($roles)
        );
    }

    public function permissions(IndexPermissionRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Permission::class);

        return response()->json([
            'success' => true,
            'data' => $this->roleService->getPermissions($request->validated()['search'] ?? null),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roleService->createRole($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Role created',
            'data' => $role,
        ], 201);
    }

    public function update(UpdateRoleRequest $request, int|string $id): JsonResponse
    {
        $role = $this->roleService->getRole($id);
        $this->authorize('update', $role);

        $updatedRole = $this->roleService->updateRole($role, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Role updated',
            'data' => $updatedRole,
        ]);
    }

    public function rolePermissions(int|string $id): JsonResponse
    {
        $role = $this->roleService->getRole($id);
        $this->authorize('view', $role);

        return response()->json([
            'success' => true,
            'data' => $this->roleService->getRolePermissions($role),
        ]);
    }

    public function assignPermissions(AssignRolePermissionsRequest $request, int|string $id): JsonResponse
    {
        $role = $this->roleService->getRole($id);
        $this->authorize('assignPermissions', $role);

        $result = $this->roleService->assignPermissions(
            $role,
            $request->validated()['permissions'] ?? []
        );

        return response()->json([
            'success' => true,
            'message' => 'Permissions updated',
            'data' => $result,
        ]);
    }
}