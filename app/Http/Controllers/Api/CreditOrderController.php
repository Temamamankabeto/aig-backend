<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CreditAccount;
use App\Models\CreditOrder;
use App\Services\CreditOrderService;
use Illuminate\Http\Request;

class CreditOrderController extends Controller
{
    public function __construct(private CreditOrderService $creditOrderService) {}

    private function requirePermission(Request $request, string $permission)
    {
        abort_unless($request->user()?->can($permission), 403, 'You are not authorized to perform this credit action.');
    }

    public function accounts(Request $request)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $q = CreditAccount::query()
            ->with(['authorizedUsers' => fn ($query) => $query->where('is_active', true)->orderBy('full_name')])
            ->latest();

        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(fn($x) => $x
                ->where('name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
            );
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        if ($request->filled('account_type')) {
            $q->where('account_type', $request->account_type);
        }

        $rows = $q->paginate(max(1, min((int) $request->query('per_page', 10), 100)));

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

    public function storeAccount(Request $request)
    {
        $this->requirePermission($request, 'credit.accounts.create');

        $data = $request->validate([
            'account_type' => 'required|in:customer,organization,staff,student',
            'customer_id' => 'nullable|integer',
            'organization_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:255',
            'credit_limit' => 'required|numeric|min:0',
            'is_credit_enabled' => 'sometimes|boolean',
            'requires_approval' => 'sometimes|boolean',
            'settlement_cycle' => 'nullable|in:daily,weekly,monthly',
            'status' => 'nullable|in:active,blocked',
        ]);

        $data['created_by'] = $request->user()->id;

        $account = CreditAccount::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Credit account created successfully.',
            'data' => $account,
        ], 201);
    }

    public function showAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $account = CreditAccount::with(['authorizedUsers', 'creditOrders.order','creditOrders.bill'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $account,
        ]);
    }

    public function updateAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $account = CreditAccount::findOrFail($id);

        $data = $request->validate([
            'account_type' => 'sometimes|in:customer,organization,staff,student',
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:60',
            'email' => 'nullable|email|max:255',
            'credit_limit' => 'sometimes|numeric|min:0',
            'is_credit_enabled' => 'sometimes|boolean',
            'requires_approval' => 'sometimes|boolean',
            'settlement_cycle' => 'nullable|in:daily,weekly,monthly',
            'status' => 'nullable|in:active,blocked',
        ]);

        $account->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Credit account updated successfully.',
            'data' => $account->fresh(),
        ]);
    }

    public function toggleAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);

        if ($account->status === 'blocked' || !$account->is_credit_enabled) {
            $account->update([
                'status' => 'active',
                'is_credit_enabled' => true,
            ]);

            $message = 'Credit account unblocked.';
        } else {
            $account->update([
                'status' => 'blocked',
                'is_credit_enabled' => false,
            ]);

            $message = 'Credit account blocked.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $account->fresh(),
        ]);
    }

    public function blockAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);

        $account->update([
            'status' => 'blocked',
            'is_credit_enabled' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Credit account blocked.',
            'data' => $account->fresh(),
        ]);
    }

    public function unblockAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);

        $account->update([
            'status' => 'active',
            'is_credit_enabled' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Credit account unblocked.',
            'data' => $account->fresh(),
        ]);
    }

    public function orders(Request $request)
    {
        $this->requirePermission($request, 'credit.orders.read');

        $q = CreditOrder::with(['account','authorizedUser','order','bill'])->latest();

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        if ($request->filled('credit_account_id')) {
            $q->where('credit_account_id', $request->integer('credit_account_id'));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->search);

            $q->where(fn($x) => $x
                ->where('credit_reference', 'like', "%{$s}%")
                ->orWhere('used_by_name', 'like', "%{$s}%")
                ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$s}%"))
                ->orWhereHas('account', fn($a) => $a->where('name', 'like', "%{$s}%"))
            );
        }

        $rows = $q->paginate(max(1, min((int) $request->query('per_page', 10), 100)));

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

    public function showOrder(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.orders.read');

        return response()->json([
            'success' => true,
            'data' => CreditOrder::with([
                'account',
                'authorizedUser',
                'order.items.menuItem',
                'bill',
                'settlements.receiver',
                'logs.actor',
            ])->findOrFail($id),
        ]);
    }

    public function createFromBill(Request $request, $billId)
    {
        $this->requirePermission($request, 'credit.orders.create');

        $data = $request->validate([
            'credit_account_id' => 'required|exists:credit_accounts,id',
            'credit_account_user_id' => 'nullable|exists:credit_account_users,id',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'override_limit' => 'sometimes|boolean',
        ]);

        if (!empty($data['override_limit'])) {
            $this->requirePermission($request, 'credit.orders.override');
        }

        $creditOrder = $this->creditOrderService->createForBill(
            Bill::findOrFail($billId),
            (int) $data['credit_account_id'],
            (int) $request->user()->id,
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            (bool) ($data['override_limit'] ?? false),
            !empty($data['credit_account_user_id']) ? (int) $data['credit_account_user_id'] : null
        );

        return response()->json([
            'success' => true,
            'message' => 'Credit order created successfully.',
            'data' => $creditOrder,
        ], 201);
    }

    public function approve(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.orders.approve');

        $order = CreditOrder::findOrFail($id);

        if ($order->status === 'credit_pending') {
            $order->update(['status' => 'credit_approved']);
            $order->bill?->update(['credit_status' => 'credit_approved']);
            $order->order?->update(['credit_status' => 'credit_approved']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Credit order approved.',
            'data' => $order->fresh(['account','authorizedUser','order','bill']),
        ]);
    }

    public function reject(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.orders.approve');

        $order = CreditOrder::findOrFail($id);

        if ($order->status === 'credit_pending') {
            $order->update(['status' => 'blocked']);
            $order->bill?->update(['credit_status' => 'blocked']);
            $order->order?->update(['credit_status' => 'blocked']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Credit order rejected.',
            'data' => $order->fresh(['account','authorizedUser','order','bill']),
        ]);
    }

    public function settle(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.orders.settle');

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,mobile,transfer',
            'reference_number' => 'nullable|string|max:255',
            'settled_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Credit settlement recorded.',
            'data' => $this->creditOrderService->settle(
                CreditOrder::findOrFail($id),
                $data,
                (int) $request->user()->id
            ),
        ], 201);
    }

    public function reportsSummary(Request $request)
    {
        $this->requirePermission($request, 'credit.reports.read');

        return response()->json([
            'success' => true,
            'data' => [
                'total_credit_orders' => CreditOrder::count(),
                'pending_approval' => CreditOrder::where('status', 'credit_pending')->count(),
                'partially_settled' => CreditOrder::where('status', 'partially_settled')->count(),
                'fully_settled' => CreditOrder::where('status', 'fully_settled')->count(),
                'overdue' => CreditOrder::where('status', '!=', 'fully_settled')->whereDate('due_date', '<', now())->count(),
                'total_outstanding' => round((float) CreditOrder::where('status', '!=', 'fully_settled')->sum('remaining_amount'), 2),
            ],
        ]);
    }
}
