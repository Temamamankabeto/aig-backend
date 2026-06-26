<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditOrderAuthorizedUser extends Model
{
    protected $fillable = [
        'credit_order_id',
        'credit_account_id',
        'credit_account_user_id',
        'allocated_amount',
        'full_name',
        'phone',
        'position',
        'employee_id',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function creditOrder()
    {
        return $this->belongsTo(CreditOrder::class);
    }

    public function account()
    {
        return $this->belongsTo(CreditAccount::class, 'credit_account_id');
    }

    public function authorizedUser()
    {
        return $this->belongsTo(CreditAccountUser::class, 'credit_account_user_id');
    }
}
