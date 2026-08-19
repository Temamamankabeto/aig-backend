<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'max:255', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where(
                    fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')->where(fn ($q) => $q->where('guard_name', 'sanctum')),
            ],
        ];
    }
}
