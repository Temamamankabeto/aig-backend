<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\CreditAccount;
use App\Models\CreditAgreement;
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
            'credit_agreement_id' => ['nullable', 'integer', 'exists:credit_agreements,id'],
            'credit_account_user_ids' => ['nullable', 'array'],
            'credit_account_user_ids.*' => ['integer', 'exists:credit_account_users,id'],
            'credit_notes' => ['nullable', 'string', 'max:1000'],
            'credit_order_mode' => ['nullable', 'in:order_based,beef_based'],
            'meal_type' => ['nullable', 'string', 'max:80'],
            'number_of_person' => ['nullable', 'integer', 'min:1'],
            'customer_tin' => ['nullable', 'string', 'max:80'],
            'items' => ['nullable', 'array'],
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


                if ($account) {
                    $agreementQuery = CreditAgreement::where('credit_account_id', $account->id)
                        ->where('status', 'active')
                        ->whereDate('start_date', '<=', now()->toDateString())
                        ->whereDate('end_date', '>=', now()->toDateString());

                    if ($this->filled('credit_agreement_id')) {
                        $agreementQuery->where('id', $this->input('credit_agreement_id'));
                    }

                    if (!$agreementQuery->exists()) {
                        $validator->errors()->add('credit_agreement_id', 'This credit account has no active agreement for today. Credit order is not allowed.');
                        return;
                    }
                }

                // Authorized users are optional in the agreement-based credit workflow.
                // Single accounts use the cashier-entered customer name on bill print; bulky accounts use the active agreement.

                if ($this->input('credit_order_mode') === 'beef_based') {
                    if (!$this->filled('meal_type')) {
                        $validator->errors()->add('meal_type', 'Meal type is required for beef-based credit orders.');
                    }
                    if (!$this->filled('number_of_person')) {
                        $validator->errors()->add('number_of_person', 'Number of persons is required for beef-based credit orders.');
                    }
                }
            }

            if ($this->input('credit_order_mode') !== 'beef_based' && collect($this->input('items', []))->isEmpty()) {
                $validator->errors()->add('items', 'At least one valid order item is required.');
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
            'credit_order_mode' => $this->filled('credit_order_mode') ? $this->input('credit_order_mode') : 'order_based',
            'meal_type' => $this->filled('meal_type') ? trim((string) $this->input('meal_type')) : $this->input('meal_type'),
            'customer_tin' => $this->filled('customer_tin') ? trim((string) $this->input('customer_tin')) : $this->input('customer_tin'),
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
