<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;

class TransferTableRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('tables.transfer'); }
    public function rules(): array
    {
        return [
            'to_waiter_ids' => ['required', 'array', 'min:1'],
            'to_waiter_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
