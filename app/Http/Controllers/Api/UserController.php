<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AssignUserRoleRequest;
use App\Http\Requests\User\IndexUserRequest;
use App\Http\Requests\User\ResetUserPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected UserService $userService
    ) {}

    public function index(IndexUserRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = $this->userService->paginateUsers($request->validated());

        return response()->json(
            $this->userService->transformPaginatedUsers($users)
        );
    }

    public function show(int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('view', $user);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully',
            'data' => $user,
        ]);
    }

    public function rolesLite(): JsonResponse
    {
        $this->authorize('rolesLite', User::class);

        return response()->json([
            'success' => true,
            'message' => 'Roles retrieved successfully',
            'data' => $this->userService->getRolesLite(),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $this->userService->createUser($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    public function update(UpdateUserRequest $request, int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('update', $user);

        $updatedUser = $this->userService->updateUser($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $updatedUser,
        ]);
    }

    public function assignRole(AssignUserRoleRequest $request, int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('assignRole', $user);

        $updatedUser = $this->userService->assignRole($user, $request->validated()['role']);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data' => $updatedUser,
        ]);
    }

    public function toggle(int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('toggle', $user);

        $updatedUser = $this->userService->toggleUser($user);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => $updatedUser,
        ]);
    }

    public function resetPassword(ResetUserPasswordRequest $request, int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('resetPassword', $user);

        $this->userService->resetPassword($user, $request->validated()['new_password']);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful',
            'data' => [
                'id' => $user->id,
            ],
        ]);
    }

    public function waitersLite(Request $request): JsonResponse
    {
        $this->authorize('waitersLite', User::class);

        return response()->json([
            'success' => true,
            'data' => $this->userService->getWaitersLite($request->get('search')),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $updatedUser = $this->userService->updateProfile(
            $user,
            $request->validated(),
            $request->file('profile')
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $updatedUser,
        ]);
    }

    public function destroy(int|string $id): JsonResponse
    {
        $user = $this->userService->getUser($id);
        $this->authorize('delete', $user);

        $this->userService->deleteUser($user, auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }
}