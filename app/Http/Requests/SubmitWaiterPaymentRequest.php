<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitWaiterPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payments.create');
    }

    public function rules(): array
    {
        return [
            'bill_id' => 'required|exists:bills,id',
            'method' => 'required|in:cash,transfer',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
            'paid_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ];
    }
}
