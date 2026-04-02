<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
        public function authorize(): bool
    {
        return (bool) $this->user()?->can('orders.update');
    }

    public function rules(): array
    {
        return [
            'order_type' => ['sometimes', Rule::in(['dine_in','takeaway','delivery'])],
            'table_id' => ['nullable','integer','exists:dining_tables,id'],
            'waiter_id' => ['nullable','integer','exists:users,id'],

            'customer_name' => ['nullable','string','max:120'],
            'customer_phone' => ['nullable','string','max:50'],
            'customer_address' => ['nullable','string','max:255'],

            'status' => ['sometimes', Rule::in([
                'pending','confirmed','in_progress','ready','served','completed','cancelled'
            ])],

            'notes' => ['nullable','string'],

            'tax' => ['nullable','numeric','min:0'],
            'service_charge' => ['nullable','numeric','min:0'],
            'discount' => ['nullable','numeric','min:0'],

            // optional replace items completely
            'items' => ['sometimes','array','min:1'],
            'items.*.menu_item_id' => ['required_with:items','integer','exists:menu_items,id'],
            'items.*.quantity' => ['required_with:items','integer','min:1'],
            'items.*.notes' => ['nullable','string'],
            'items.*.modifiers' => ['nullable','array'],
        ];
    }
}