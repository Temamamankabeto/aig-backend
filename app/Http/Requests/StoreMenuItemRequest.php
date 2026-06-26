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
        $trackingMode = $this->input('inventory_tracking_mode');
        if ($trackingMode === null || $trackingMode === '') {
            $trackingMode = 'none';
        }

        $hasIngredients = $trackingMode === 'recipe'
            ? true
            : $this->normalizeBoolean($this->input('has_ingredients'), false);

        $this->merge([
            'is_active' => $this->normalizeBoolean($this->input('is_active'), true),
            'is_available' => $this->normalizeBoolean($this->input('is_available'), true),
            'is_featured' => $this->normalizeBoolean($this->input('is_featured'), false),
            'has_ingredients' => $hasIngredients,
            'inventory_tracking_mode' => $trackingMode,
            'direct_inventory_item_id' => $trackingMode === 'direct'
                ? $this->input('direct_inventory_item_id')
                : null,
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
            'has_ingredients' => ['nullable', 'boolean'],
            'inventory_tracking_mode' => ['required', Rule::in(['recipe', 'direct', 'none'])],
            'direct_inventory_item_id' => [
                'nullable',
                'integer',
                'required_if:inventory_tracking_mode,direct',
                'exists:inventory_items,id',
            ],
            'menu_mode' => ['nullable', Rule::in(['normal', 'spatial'])],
            'modifiers' => ['nullable'],
            'prep_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_tracking_mode.required' => 'Please select how this menu item affects inventory.',
            'inventory_tracking_mode.in' => 'Inventory tracking mode must be recipe, direct, or none.',
            'direct_inventory_item_id.required_if' => 'Please select an inventory item when direct stock tracking is selected.',
            'direct_inventory_item_id.exists' => 'The selected direct inventory item does not exist.',
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
