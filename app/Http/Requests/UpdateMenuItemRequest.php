<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuItemRequest extends FormRequest
{
        public function authorize(): bool
    {
        return (bool) $this->user()?->can('menu.update');
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes','exists:menu_categories,id'],
            'name' => ['sometimes','string','max:255'],
            'description' => ['sometimes','nullable','string'],
            'type' => ['sometimes','in:food,drink'],
            'price' => ['sometimes','numeric','min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'modifiers' => ['sometimes','nullable','array'],
            'prep_minutes' => ['sometimes','nullable','integer','min:0'],
            'is_available' => ['sometimes','boolean'],
            'is_active' => ['sometimes','boolean'],
        ];
    }
}