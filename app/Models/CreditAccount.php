<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditAccount extends Model
{
    protected $fillable = [
        'account_type',
        'customer_id',
        'organization_id',
        'name',
        'tin_number',
        'representative_name',
        'representative_phone',
        'phone',
        'email',
        'credit_limit',
        'current_balance',
        'is_credit_enabled',
        'requires_approval',
        'settlement_cycle',
        'status',
        'created_by',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_credit_enabled' => 'boolean',
        'requires_approval' => 'boolean',
    ];

    public function creditOrders()
    {
        return $this->hasMany(CreditOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function authorizedUsers()
    {
        return $this->hasMany(CreditAccountUser::class);
    }

    public function agreements()
    {
        return $this->hasMany(CreditAgreement::class);
    }

    public function activeAgreements()
    {
        return $this->hasMany(CreditAgreement::class)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString());
    }
}

