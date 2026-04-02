<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('shifts.manage');
    }

    public function rules(): array
    {
        return [
            'opening_cash' => 'required|numeric|min:0',
        ];
    }
}
