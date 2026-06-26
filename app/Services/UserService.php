<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function paginateUsers(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        $query = User::query()->with('roles');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'disabled') {
            $query->where('is_active', false);
        }

        return $query->latest()->paginate($perPage);
    }

    public function transformPaginatedUsers(LengthAwarePaginator $users): array
    {
        return [
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => collect($users->items())->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $user->is_active ? 'active' : 'disabled',
                    'role' => $user->roles->pluck('name')->first(),
                    'profile_image_url' => $user->profile_image_url,
                    'created_at' => $user->created_at,
                ];
            })->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ];
    }

    public function getUser(int|string $id): User
    {
        return User::with('roles')->findOrFail($id);
    }

    public function getRolesLite()
    {
        return Role::query()
            ->where('guard_name', 'sanctum')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    public function createUser(array $data): User
    {
        $role = $this->findRole($data['role']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $user->syncRoles([$role->name]);

        return $user->load('roles');
    }

    public function updateUser(User $user, array $data): User
    {
        $role = $this->findRole($data['role']);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
        ]);

        $user->syncRoles([$role->name]);

        return $user->load('roles');
    }

    public function assignRole(User $user, string $roleName): User
    {
        $role = $this->findRole($roleName);

        $user->syncRoles([$role->name]);

        return $user->load('roles');
    }

    public function toggleUser(User $user): User
    {
        $user->is_active = !$user->is_active;
        $user->save();

        return $user->load('roles');
    }

    public function resetPassword(User $user, string $newPassword): User
    {
        $user->password = Hash::make($newPassword);
        $user->save();

        return $user;
    }

    public function getWaitersLite(?string $search = null)
    {
        $query = User::query()
            ->select('id', 'name')
            ->whereHas('roles', function ($q) {
                $q->where('name', 'Waiter');
            });

        $search = trim((string) $search);

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderBy('name')->get();
    }

    public function updateProfile(User $user, array $data, ?UploadedFile $profileFile = null): User
    {
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'];

        if (!empty($data['new_password'])) {
            if (empty($data['old_password'])) {
                throw ValidationException::withMessages([
                    'old_password' => ['Old password is required when setting a new password.'],
                ]);
            }

            if (!Hash::check($data['old_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'old_password' => ['The provided old password is incorrect.'],
                ]);
            }

            $user->password = Hash::make($data['new_password']);
        }

        if ($profileFile) {
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }

            $path = $profileFile->store('users/profile-images', 'public');
            $user->profile_image = $path;
        }

        $user->save();

        return $user->load('roles');
    }

    public function deleteUser(User $user, ?int $authId = null): void
    {
        if ($authId && $authId === $user->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        $user->syncRoles([]);

        if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
            Storage::disk('public')->delete($user->profile_image);
        }

        $user->delete();
    }

    protected function findRole(string $roleName): Role
    {
        return Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'sanctum')
            ->firstOrFail();
    }
}