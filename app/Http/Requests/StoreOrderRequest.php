<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\CreditAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Order::class);
    }

    public function rules(): array
    {
        return [
            'order_type' => ['required', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_id' => ['nullable', 'integer', 'exists:dining_tables,id'],
            'waiter_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', 'in:regular,cash,card,mobile,transfer,credit'],
            'credit_account_id' => ['nullable', 'integer', 'exists:credit_accounts,id'],
            'credit_account_user_id' => ['nullable', 'integer', 'exists:credit_account_users,id'],
            'credit_account_user_ids' => ['nullable', 'array'],
            'credit_account_user_ids.*' => ['integer', 'exists:credit_account_users,id'],
            'credit_notes' => ['nullable', 'string', 'max:1000'],
            'override_credit_limit' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.note' => ['nullable', 'string'],
            'items.*.modifiers' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $orderType = $this->input('order_type');
            $isCashierRoute = $this->isCashierOrderRequest();

            if ($orderType === 'dine_in' && !$this->filled('table_id')) {
                $validator->errors()->add('table_id', 'Table is required for dine-in orders.');
            }

            if ($orderType === 'takeaway' && $this->filled('table_id')) {
                $validator->errors()->add('table_id', 'Table is not allowed for takeaway orders.');
            }

            if ($orderType === 'delivery' && !$this->filled('customer_address')) {
                $validator->errors()->add('customer_address', 'Customer address is required for delivery orders.');
            }

            if ($isCashierRoute && !in_array($orderType, ['dine_in', 'takeaway'], true)) {
                $validator->errors()->add('order_type', 'Cashier can only create dine-in or takeaway orders.');
            }

            if ($isCashierRoute && !$this->filled('waiter_id')) {
                $validator->errors()->add('waiter_id', 'Waiter is required for cashier order.');
            }

            if ($this->input('payment_type') === 'credit') {
                if (!$this->filled('credit_account_id')) {
                    $validator->errors()->add('credit_account_id', 'Credit account is required for credit orders.');
                    return;
                }

                $account = CreditAccount::find($this->input('credit_account_id'));

                if ($account && strtolower((string) $account->account_type) === 'organization') {
                    $ids = collect($this->input('credit_account_user_ids', []))
                        ->filter(fn ($id) => !empty($id))
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values();

                    if ($ids->isEmpty() && $this->filled('credit_account_user_id')) {
                        $ids = collect([(int) $this->input('credit_account_user_id')]);
                    }

                    if ($ids->isEmpty()) {
                        $validator->errors()->add('credit_account_user_ids', 'At least one authorized person is required for organization credit accounts.');
                        return;
                    }

                    $validCount = $account->authorizedUsers()
                        ->whereIn('id', $ids->all())
                        ->where('is_active', true)
                        ->count();

                    if ($validCount !== $ids->count()) {
                        $validator->errors()->add('credit_account_user_ids', 'One or more selected authorized persons are not active for this credit account.');
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))->map(function ($item) {
            $noteValue = $item['notes'] ?? $item['note'] ?? null;
            return [...$item, 'notes' => is_string($noteValue) ? trim($noteValue) : $noteValue];
        })->all();

        $userIds = collect($this->input('credit_account_user_ids', []))
            ->filter(fn ($id) => !empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($userIds) && $this->filled('credit_account_user_id')) {
            $userIds = [(int) $this->input('credit_account_user_id')];
        }

        $this->merge([
            'customer_name' => $this->filled('customer_name') ? trim((string) $this->input('customer_name')) : $this->input('customer_name'),
            'customer_phone' => $this->filled('customer_phone') ? trim((string) $this->input('customer_phone')) : $this->input('customer_phone'),
            'customer_address' => $this->filled('customer_address') ? trim((string) $this->input('customer_address')) : $this->input('customer_address'),
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : $this->input('notes'),
            'items' => $items,
            'credit_account_user_ids' => $userIds,
            'credit_account_user_id' => $userIds[0] ?? $this->input('credit_account_user_id'),
        ]);
    }

    private function isCashierOrderRequest(): bool
    {
        $path = $this->path();
        $routeName = optional($this->route())->getName();
        $action = optional($this->route())->getActionName();

        return str_contains($path, 'cashier/orders')
            || ($routeName && str_contains($routeName, 'cashier'))
            || ($action && str_contains($action, 'CashierOrderController'));
    }
}
