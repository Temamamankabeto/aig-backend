<?php

namespace App\Http\Requests;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = MenuItem::find($this->route('id'));

        return $item
            ? (bool) $this->user()?->can('update', $item)
            : false;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('is_active')) {
            $merged['is_active'] = $this->normalizeBoolean($this->input('is_active'), true);
        }

        if ($this->has('is_available')) {
            $merged['is_available'] = $this->normalizeBoolean($this->input('is_available'), true);
        }

        if ($this->has('is_featured')) {
            $merged['is_featured'] = $this->normalizeBoolean($this->input('is_featured'), false);
        }

        if ($this->has('remove_image')) {
            $merged['remove_image'] = $this->normalizeBoolean($this->input('remove_image'), false);
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:menu_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'required', Rule::in(['food', 'drink'])],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'menu_mode' => ['nullable', Rule::in(['normal', 'spatial'])],
            'modifiers' => ['nullable'],
            'prep_minutes' => ['nullable', 'integer', 'min:0'],
            'remove_image' => ['nullable', 'boolean'],
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