<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CreditAccount;
use App\Models\CreditAgreement;
use App\Models\CreditOrder;
use App\Services\CreditOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CreditOrderController extends Controller
{
    public function __construct(private CreditOrderService $creditOrderService) {}

    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'You are not authorized to perform this credit action.');
    }

    private function activeAgreementQuery()
    {
        return CreditAgreement::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString());
    }

    public function accounts(Request $request)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $q = CreditAccount::query()
            ->with([
                'activeAgreements',
                'agreements' => fn ($query) => $query->latest(),
                'authorizedUsers' => fn ($query) => $query->where('is_active', true)->orderBy('full_name'),
            ])
            ->latest();

        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(fn ($x) => $x
                ->where('name', 'like', "%{$s}%")
                ->orWhere('tin_number', 'like', "%{$s}%")
                ->orWhere('representative_name', 'like', "%{$s}%")
                ->orWhere('representative_phone', 'like', "%{$s}%")
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
            'message' => 'Credit accounts fetched successfully.',
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
            'account_type' => 'required|in:bulky,single',
            'name' => 'required|string|max:255',
            'tin_number' => 'nullable|string|max:80',
            'representative_name' => 'required|string|max:255',
            'representative_phone' => 'required|string|max:80',
            'phone' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|in:active,blocked',
            'is_credit_enabled' => 'sometimes|boolean',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'active';
        $data['is_credit_enabled'] = $data['is_credit_enabled'] ?? true;
        $data['requires_approval'] = false;
        $data['settlement_cycle'] = null;
        $data['credit_limit'] = 0;
        $data['current_balance'] = 0;

        $account = CreditAccount::create($data)->fresh(['agreements', 'activeAgreements']);

        return response()->json([
            'success' => true,
            'message' => 'Credit account created successfully. Add agreement before creating credit orders.',
            'data' => $account,
        ], 201);
    }

    public function showAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $account = CreditAccount::with([
            'authorizedUsers',
            'agreements' => fn ($query) => $query->latest(),
            'activeAgreements',
            'creditOrders.agreement',
            'creditOrders.order',
            'creditOrders.bill',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Credit account fetched successfully.',
            'data' => $account,
        ]);
    }

    public function updateAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $account = CreditAccount::findOrFail($id);

        $data = $request->validate([
            'account_type' => 'sometimes|in:bulky,single',
            'name' => 'sometimes|string|max:255',
            'tin_number' => 'nullable|string|max:80',
            'representative_name' => 'sometimes|string|max:255',
            'representative_phone' => 'sometimes|string|max:80',
            'phone' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:255',
            'status' => 'nullable|in:active,blocked',
            'is_credit_enabled' => 'sometimes|boolean',
        ]);

        unset($data['credit_limit'], $data['current_balance'], $data['settlement_cycle'], $data['requires_approval']);

        $account->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Credit account updated successfully.',
            'data' => $account->fresh(['agreements', 'activeAgreements']),
        ]);
    }

    public function toggleAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);
        $blocked = $account->status === 'blocked' || !$account->is_credit_enabled;

        $account->update([
            'status' => $blocked ? 'active' : 'blocked',
            'is_credit_enabled' => $blocked,
        ]);

        return response()->json([
            'success' => true,
            'message' => $blocked ? 'Credit account unblocked.' : 'Credit account blocked.',
            'data' => $account->fresh(['agreements', 'activeAgreements']),
        ]);
    }

    public function blockAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);
        $account->update(['status' => 'blocked', 'is_credit_enabled' => false]);

        return response()->json(['success' => true, 'message' => 'Credit account blocked.', 'data' => $account->fresh()]);
    }

    public function unblockAccount(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.accounts.block');

        $account = CreditAccount::findOrFail($id);
        $account->update(['status' => 'active', 'is_credit_enabled' => true]);

        return response()->json(['success' => true, 'message' => 'Credit account unblocked.', 'data' => $account->fresh()]);
    }

    public function agreements(Request $request, $accountId)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $agreements = CreditAgreement::where('credit_account_id', $accountId)->latest()->paginate(max(1, min((int) $request->query('per_page', 20), 100)));

        return response()->json([
            'success' => true,
            'message' => 'Credit agreements fetched successfully.',
            'data' => $agreements->items(),
            'meta' => [
                'current_page' => $agreements->currentPage(),
                'last_page' => $agreements->lastPage(),
                'per_page' => $agreements->perPage(),
                'total' => $agreements->total(),
            ],
        ]);
    }

    public function storeAgreement(Request $request, $accountId)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $account = CreditAccount::findOrFail($accountId);

        $data = $request->validate([
            'meal_type' => 'required|string|max:255',
            'agreement_type' => 'nullable|in:order_based,beef_based',
            'number_of_person' => 'required|integer|min:1',
            'single_person_name' => 'nullable|string|max:255',
            'price_per_person' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_price' => 'nullable|numeric|min:0',
            'agreement_letter' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'status' => 'nullable|in:active,disabled,expired',
        ]);

        if ($account->account_type === 'single' && empty($data['single_person_name'])) {
            return response()->json(['success' => false, 'message' => 'Single account agreement requires person name.', 'data' => null], 422);
        }

        $data['credit_account_id'] = $account->id;
        $data['created_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? 'active';
        $data['agreement_type'] = $data['agreement_type'] ?? 'order_based';
        $data['total_price'] = $data['total_price'] ?? round((float) $data['number_of_person'] * (float) $data['price_per_person'], 2);

        if ($request->hasFile('agreement_letter')) {
            $data['agreement_letter_path'] = $request->file('agreement_letter')->store('credit-agreements', 'public');
        }

        unset($data['agreement_letter']);

        $agreement = CreditAgreement::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Credit agreement created successfully.',
            'data' => $agreement->fresh(),
        ], 201);
    }

    public function updateAgreement(Request $request, $accountId, $agreementId)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $agreement = CreditAgreement::where('credit_account_id', $accountId)->findOrFail($agreementId);
        $account = CreditAccount::findOrFail($accountId);

        $data = $request->validate([
            'meal_type' => 'sometimes|string|max:255',
            'agreement_type' => 'nullable|in:order_based,beef_based',
            'number_of_person' => 'sometimes|integer|min:1',
            'single_person_name' => 'nullable|string|max:255',
            'price_per_person' => 'sometimes|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'total_price' => 'nullable|numeric|min:0',
            'agreement_letter' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'status' => 'nullable|in:active,disabled,expired',
        ]);

        if ($account->account_type === 'single' && array_key_exists('single_person_name', $data) && empty($data['single_person_name'])) {
            return response()->json(['success' => false, 'message' => 'Single account agreement requires person name.', 'data' => null], 422);
        }

        if ($request->hasFile('agreement_letter')) {
            if ($agreement->agreement_letter_path) {
                Storage::disk('public')->delete($agreement->agreement_letter_path);
            }
            $data['agreement_letter_path'] = $request->file('agreement_letter')->store('credit-agreements', 'public');
        }

        unset($data['agreement_letter']);

        if (!isset($data['total_price']) && (isset($data['number_of_person']) || isset($data['price_per_person']))) {
            $data['total_price'] = round((float) ($data['number_of_person'] ?? $agreement->number_of_person) * (float) ($data['price_per_person'] ?? $agreement->price_per_person), 2);
        }

        $agreement->update($data);

        return response()->json(['success' => true, 'message' => 'Credit agreement updated successfully.', 'data' => $agreement->fresh()]);
    }

    public function disableAgreement(Request $request, $accountId, $agreementId)
    {
        $this->requirePermission($request, 'credit.accounts.update');

        $agreement = CreditAgreement::where('credit_account_id', $accountId)->findOrFail($agreementId);
        $agreement->update(['status' => 'disabled']);

        return response()->json(['success' => true, 'message' => 'Credit agreement disabled successfully.', 'data' => $agreement->fresh()]);
    }

    public function orders(Request $request)
    {
        $this->requirePermission($request, 'credit.orders.read');

        $q = CreditOrder::with(['account', 'agreement', 'authorizedUser', 'order', 'bill'])->latest();

        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('credit_account_id')) $q->where('credit_account_id', $request->integer('credit_account_id'));

        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(fn ($x) => $x
                ->where('credit_reference', 'like', "%{$s}%")
                ->orWhere('used_by_name', 'like', "%{$s}%")
                ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$s}%"))
                ->orWhereHas('account', fn ($a) => $a->where('name', 'like', "%{$s}%"))
                ->orWhereHas('agreement', fn ($a) => $a->where('meal_type', 'like', "%{$s}%"))
            );
        }

        $rows = $q->paginate(max(1, min((int) $request->query('per_page', 10), 100)));

        return response()->json(['success' => true, 'message' => 'Credit orders fetched successfully.', 'data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function showOrder(Request $request, $id)
    {
        $this->requirePermission($request, 'credit.orders.read');

        return response()->json(['success' => true, 'message' => 'Credit order fetched successfully.', 'data' => CreditOrder::with(['account', 'agreement', 'authorizedUser', 'order.items.menuItem', 'bill', 'settlements.receiver', 'logs.actor'])->findOrFail($id)]);
    }

    public function createFromBill(Request $request, $billId)
    {
        $this->requirePermission($request, 'credit.orders.create');

        $data = $request->validate([
            'credit_account_id' => 'required|exists:credit_accounts,id',
            'credit_agreement_id' => 'nullable|exists:credit_agreements,id',
            'credit_account_user_id' => 'nullable|exists:credit_account_users,id',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $creditOrder = $this->creditOrderService->createForBill(
            Bill::findOrFail($billId),
            (int) $data['credit_account_id'],
            (int) $request->user()->id,
            $data['due_date'] ?? null,
            $data['notes'] ?? null,
            false,
            !empty($data['credit_account_user_id']) ? (int) $data['credit_account_user_id'] : null,
            false,
            !empty($data['credit_agreement_id']) ? (int) $data['credit_agreement_id'] : null
        );

        return response()->json(['success' => true, 'message' => 'Credit order created successfully.', 'data' => $creditOrder], 201);
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
        return response()->json(['success' => true, 'message' => 'Credit order approved.', 'data' => $order->fresh(['account', 'agreement', 'authorizedUser', 'order', 'bill'])]);
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
        return response()->json(['success' => true, 'message' => 'Credit order rejected.', 'data' => $order->fresh(['account', 'agreement', 'authorizedUser', 'order', 'bill'])]);
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
        return response()->json(['success' => true, 'message' => 'Credit settlement recorded.', 'data' => $this->creditOrderService->settle(CreditOrder::findOrFail($id), $data, (int) $request->user()->id)], 201);
    }


    public function agreementFile(Request $request, $agreementId)
    {
        $this->requirePermission($request, 'credit.accounts.read');

        $agreement = CreditAgreement::findOrFail($agreementId);

        if (! $agreement->agreement_letter_path || ! Storage::disk('public')->exists($agreement->agreement_letter_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Agreement file was not found.',
                'data' => null,
            ], 404);
        }

        return Storage::disk('public')->download($agreement->agreement_letter_path);
    }

    public function reportsSummary(Request $request)
    {
        $this->requirePermission($request, 'credit.reports.read');
        return response()->json(['success' => true, 'message' => 'Credit report summary fetched successfully.', 'data' => ['total_credit_orders' => CreditOrder::count(), 'active_agreements' => $this->activeAgreementQuery()->count(), 'expired_agreements' => CreditAgreement::whereDate('end_date', '<', now()->toDateString())->count(), 'partially_settled' => CreditOrder::where('status', 'partially_settled')->count(), 'fully_settled' => CreditOrder::where('status', 'fully_settled')->count(), 'total_outstanding' => round((float) CreditOrder::where('status', '!=', 'fully_settled')->sum('remaining_amount'), 2)]]);
    }
}
