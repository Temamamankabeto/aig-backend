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

        foreach (['is_active', 'is_available', 'is_featured', 'remove_image'] as $field) {
            if ($this->exists($field)) {
                $merged[$field] = $this->normalizeBoolean($this->input($field));
            }
        }

        if ($this->exists('category_id') && $this->input('category_id') !== '') {
            $merged['category_id'] = (int) $this->input('category_id');
        }

        if ($this->exists('price') && $this->input('price') !== '') {
            $merged['price'] = (float) $this->input('price');
        }

        if ($this->exists('prep_minutes') && $this->input('prep_minutes') !== '') {
            $merged['prep_minutes'] = (int) $this->input('prep_minutes');
        }

        if ($this->exists('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->exists('description')) {
            $merged['description'] = $this->input('description') === '' ? null : $this->input('description');
        }

        if ($this->exists('menu_mode') && $this->input('menu_mode') === '') {
            $merged['menu_mode'] = null;
        }

        if ($this->exists('modifiers') && $this->input('modifiers') === '') {
            $merged['modifiers'] = null;
        }

        if ($this->exists('prep_minutes') && $this->input('prep_minutes') === '') {
            $merged['prep_minutes'] = null;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'category_id'   => ['sometimes', 'required', 'integer', 'exists:menu_categories,id'],
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'description'   => ['sometimes', 'nullable', 'string'],
            'type'          => ['sometimes', 'required', Rule::in(['food', 'drink'])],
            'price'         => ['sometimes', 'required', 'numeric', 'min:0'],
            'image'         => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
            'is_available'  => ['sometimes', 'boolean'],
            'is_active'     => ['sometimes', 'boolean'],
            'is_featured'   => ['sometimes', 'boolean'],
            'menu_mode'     => ['sometimes', 'nullable', Rule::in(['normal', 'spatial'])],
            'modifiers'     => ['sometimes', 'nullable'],
            'prep_minutes'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'remove_image'  => ['sometimes', 'boolean'],
        ];
    }

    protected function validationData(): array
    {
        return $this->all();
    }

    private function normalizeBoolean($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return match ($value) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}