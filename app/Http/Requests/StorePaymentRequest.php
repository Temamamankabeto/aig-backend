<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payments.create');
    }

    public function rules(): array
    {
        return [
            'method' => 'required|in:cash,card,mobile,transfer',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
            'cash_shift_id' => 'nullable|exists:cash_shifts,id',
        ];
    }
}
