<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditAccount;
use App\Models\CreditAccountUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CreditAccountUserController extends Controller
{
    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'You are not authorized to perform this credit action.');
    }

    private function requireAnyPermission(Request $request, array $permissions): void
    {
        $user = $request->user();
        abort_unless($user && collect($permissions)->contains(fn ($permission) => $user->can($permission)), 403, 'You are not authorized to perform this credit action.');
    }

    public function index(Request $request, $accountId)
    {
        $this->requireAnyPermission($request, ['credit.accounts.read', 'credit.orders.create', 'orders.create']);

        $account = CreditAccount::findOrFail($accountId);
        $query = $account->authorizedUsers()->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%")
                    ->orWhere('id_number', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $perPage = max(1, min((int) $request->query('per_page', 50), 100));
        $rows = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function store(Request $request, $accountId)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $account = CreditAccount::findOrFail($accountId);

        if ($account->account_type !== 'organization') {
            return response()->json([
                'success' => false,
                'message' => 'Authorized users can only be added to organization credit accounts.',
            ], 422);
        }

        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:60',
            'employee_id' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:120',
            'id_number' => 'nullable|string|max:120',
            'daily_limit' => 'nullable|numeric|min:0',
            'monthly_limit' => 'nullable|numeric|min:0',
            'pin' => 'nullable|string|min:4|max:12|confirmed',
            'pin_enabled' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if (!empty($data['pin'])) {
            $data['pin_hash'] = Hash::make($data['pin']);
        }
        unset($data['pin'], $data['pin_confirmation']);

        $data['credit_account_id'] = $account->id;
        $data['created_by'] = $request->user()->id;
        $data['pin_enabled'] = $request->has('pin_enabled') ? $request->boolean('pin_enabled') : true;

        $user = CreditAccountUser::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Authorized credit user created successfully.',
            'data' => $user,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $user = CreditAccountUser::findOrFail($id);

        $data = $request->validate([
            'full_name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:60',
            'employee_id' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:120',
            'id_number' => 'nullable|string|max:120',
            'daily_limit' => 'nullable|numeric|min:0',
            'monthly_limit' => 'nullable|numeric|min:0',
            'pin' => 'nullable|string|min:4|max:12|confirmed',
            'pin_enabled' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if (!empty($data['pin'])) {
            $data['pin_hash'] = Hash::make($data['pin']);
        }
        unset($data['pin'], $data['pin_confirmation']);

        if ($request->has('pin_enabled')) {
            $data['pin_enabled'] = $request->boolean('pin_enabled');
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Authorized credit user updated successfully.',
            'data' => $user->fresh(),
        ]);
    }

    public function toggle(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $user = CreditAccountUser::findOrFail($id);
        $user->update(['is_active' => ! (bool) $user->is_active]);

        return response()->json([
            'success' => true,
            'message' => $user->fresh()->is_active ? 'Authorized credit user activated.' : 'Authorized credit user deactivated.',
            'data' => $user->fresh(),
        ]);
    }
}
