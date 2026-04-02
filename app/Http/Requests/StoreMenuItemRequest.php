<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuItemRequest extends FormRequest
{
        public function authorize(): bool
    {
        return (bool) $this->user()?->can('menu.create');
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required','exists:menu_categories,id'],
            'name' => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'type' => ['required','in:food,drink'],
            'price' => ['required','numeric','min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'modifiers' => ['nullable','array'],
            'prep_minutes' => ['nullable','integer','min:0'],
            'is_available' => ['nullable','boolean'],
            'is_active' => ['nullable','boolean'],
        ];
    }
}