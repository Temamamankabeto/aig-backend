<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiningTableRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('tables.create'); }

    public function rules(): array
    {
        return [
            'table_number' => ['required', 'string', 'max:50', 'unique:dining_tables,table_number'],
            'name' => ['nullable', 'string', 'max:100'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'section' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['available', 'occupied', 'reserved', 'cleaning', 'out_of_service'])],
            'is_public' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'waiter_ids' => ['nullable', 'array'],
            'waiter_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
