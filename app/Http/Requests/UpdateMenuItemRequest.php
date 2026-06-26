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

        foreach (['is_active', 'is_available', 'is_featured', 'remove_image', 'has_ingredients'] as $field) {
            if ($this->has($field)) {
                $merged[$field] = $this->normalizeBoolean($this->input($field));
            }
        }

        if ($this->has('inventory_tracking_mode')) {
            $mode = $this->input('inventory_tracking_mode');

            if ($mode === '') {
                $merged['inventory_tracking_mode'] = null;
            } elseif ($mode !== 'direct') {
                $merged['direct_inventory_item_id'] = null;
            }
        }

        if ($this->has('direct_inventory_item_id')) {
            $merged['direct_inventory_item_id'] = $this->input('direct_inventory_item_id') === ''
                ? null
                : (int) $this->input('direct_inventory_item_id');
        }

        if ($this->has('category_id') && $this->input('category_id') !== '') {
            $merged['category_id'] = (int) $this->input('category_id');
        }

        if ($this->has('price') && $this->input('price') !== '') {
            $merged['price'] = (float) $this->input('price');
        }

        if ($this->has('prep_minutes')) {
            $merged['prep_minutes'] = $this->input('prep_minutes') === ''
                ? null
                : (int) $this->input('prep_minutes');
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if ($this->has('description')) {
            $merged['description'] = $this->input('description') === ''
                ? null
                : $this->input('description');
        }

        if ($this->has('menu_mode')) {
            $merged['menu_mode'] = $this->input('menu_mode') === ''
                ? null
                : $this->input('menu_mode');
        }

        if ($this->has('modifiers') && $this->input('modifiers') === '') {
            $merged['modifiers'] = null;
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
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'required', Rule::in(['food', 'drink'])],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_available' => ['sometimes', 'nullable', 'boolean'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'is_featured' => ['sometimes', 'nullable', 'boolean'],
            'has_ingredients' => ['sometimes', 'nullable', 'boolean'],
            'inventory_tracking_mode' => ['sometimes', 'required', Rule::in(['recipe', 'direct', 'none'])],
            'direct_inventory_item_id' => [
                'nullable',
                'integer',
                'required_if:inventory_tracking_mode,direct',
                'exists:inventory_items,id',
            ],
            'menu_mode' => ['sometimes', 'nullable', Rule::in(['normal', 'spatial'])],
            'modifiers' => ['sometimes', 'nullable'],
            'prep_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'remove_image' => ['sometimes', 'nullable', 'boolean'],
        ];
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
