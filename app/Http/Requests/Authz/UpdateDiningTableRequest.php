<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiningTableRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('tables.update'); }
    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'table_number' => ['required', 'string', 'max:50', Rule::unique('dining_tables', 'table_number')->ignore($id)],
            'capacity' => ['required', 'integer', 'min:1'],
            'section' => ['nullable', 'string', 'max:100'],
        ];
    }
}
