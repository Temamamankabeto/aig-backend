<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetTableStatusRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('tables.update'); }
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['available','occupied','reserved','cleaning'])]];
    }
}
