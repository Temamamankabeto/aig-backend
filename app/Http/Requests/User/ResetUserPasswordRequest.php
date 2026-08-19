<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_password' => ['required', 'string', 'max:255', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ];
    }
}
