<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('menu.create');
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                'exists:menu_categories,id'
            ],

            'name' => [
                'required',
                'string',
                'max:255'
            ],

            'description' => [
                'nullable',
                'string'
            ],

            'type' => [
                'required',
                Rule::in(['food', 'drink'])
            ],

            'price' => [
                'required',
                'numeric',
                'min:0'
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048'
            ],

            'menu_mode' => [
                'required',
                Rule::in(['normal', 'spatial'])
            ],

            'modifiers' => [
                'nullable',
                'array'
            ],

            'prep_minutes' => [
                'nullable',
                'integer',
                'min:0',
                'max:600'
            ],

            'is_available' => [
                'sometimes',
                'boolean'
            ],

            'is_active' => [
                'sometimes',
                'boolean'
            ],

            'is_featured' => [
                'sometimes',
                'boolean'
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_available' => $this->boolean('is_available'),
            'is_active' => $this->boolean('is_active'),
            'is_featured' => $this->boolean('is_featured'),
        ]);
    }
}