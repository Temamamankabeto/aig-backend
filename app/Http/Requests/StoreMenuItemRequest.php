<?php

namespace App\Http\Requests;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', MenuItem::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->normalizeBoolean($this->input('is_active'), true),
            'is_available' => $this->normalizeBoolean($this->input('is_available'), true),
            'is_featured' => $this->normalizeBoolean($this->input('is_featured'), false),
        ]);
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['food', 'drink'])],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'menu_mode' => ['nullable', Rule::in(['normal', 'spatial'])],
            'modifiers' => ['nullable'],
            'prep_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.max' => 'The menu item image may not be greater than 2MB.',
            'image.mimes' => 'The menu item image must be a JPG, JPEG, PNG, or WEBP file.',
        ];
    }

    private function normalizeBoolean($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
