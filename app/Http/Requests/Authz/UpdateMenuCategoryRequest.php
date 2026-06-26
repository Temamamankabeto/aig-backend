<?php

namespace App\Http\Requests\Authz;

class UpdateMenuCategoryRequest extends StoreMenuCategoryRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('menu.update');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
