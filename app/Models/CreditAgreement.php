<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreditAgreement extends Model
{
    protected $fillable = [
        'credit_account_id',
        'meal_type',
        'agreement_type',
        'number_of_person',
        'single_person_name',
        'price_per_person',
        'start_date',
        'end_date',
        'total_price',
        'agreement_letter_path',
        'status',
        'created_by',
    ];

    protected $casts = [
        'number_of_person' => 'integer',
        'price_per_person' => 'decimal:2',
        'total_price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected $appends = ['agreement_letter_url', 'is_active_now'];

    public function account()
    {
        return $this->belongsTo(CreditAccount::class, 'credit_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creditOrders()
    {
        return $this->hasMany(CreditOrder::class, 'credit_agreement_id');
    }

    protected function agreementLetterUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->agreement_letter_path ? Storage::disk('public')->url($this->agreement_letter_path) : null,
        );
    }

    protected function isActiveNow(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status === 'active'
                && $this->start_date?->lte(now()->toDateString())
                && $this->end_date?->gte(now()->toDateString()),
        );
    }
}
