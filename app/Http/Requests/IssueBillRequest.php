<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bills.issue');
    }

    public function rules(): array
    {
        return [];
    }
}
