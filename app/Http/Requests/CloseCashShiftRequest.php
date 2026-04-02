<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shifts.manage');
    }

    public function rules(): array
    {
        return [
            'closing_cash' => 'required|numeric|min:0',
        ];
    }
}
