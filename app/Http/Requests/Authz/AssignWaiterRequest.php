<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;

class AssignWaiterRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('tables.assign'); }
    public function rules(): array
    {
        return [
            'waiter_ids' => ['required', 'array', 'min:1'],
            'waiter_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
