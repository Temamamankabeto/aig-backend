<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('orders.create');
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

            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
            'items.*.modifiers' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $orderType = $this->input('order_type');
            $isCashierRoute = $this->isCashierOrderRequest();

            // dine-in must have table
            if ($orderType === 'dine_in' && !$this->filled('table_id')) {
                $validator->errors()->add('table_id', 'Table is required for dine-in orders.');
            }

            // takeaway must not carry table
            if ($orderType === 'takeaway' && $this->filled('table_id')) {
                $validator->errors()->add('table_id', 'Table is not allowed for takeaway orders.');
            }

            // delivery must have address
            if ($orderType === 'delivery' && !$this->filled('customer_address')) {
                $validator->errors()->add('customer_address', 'Customer address is required for delivery orders.');
            }

            // cashier can create only dine_in or takeaway
            if ($isCashierRoute && !in_array($orderType, ['dine_in', 'takeaway'], true)) {
                $validator->errors()->add('order_type', 'Cashier can only create dine-in or takeaway orders.');
            }

            // cashier must assign waiter
            if ($isCashierRoute && !$this->filled('waiter_id')) {
                $validator->errors()->add('waiter_id', 'Waiter is required for cashier order.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_name' => $this->filled('customer_name')
                ? trim((string) $this->input('customer_name'))
                : $this->input('customer_name'),

            'customer_phone' => $this->filled('customer_phone')
                ? trim((string) $this->input('customer_phone'))
                : $this->input('customer_phone'),

            'customer_address' => $this->filled('customer_address')
                ? trim((string) $this->input('customer_address'))
                : $this->input('customer_address'),

            'notes' => $this->filled('notes')
                ? trim((string) $this->input('notes'))
                : $this->input('notes'),
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