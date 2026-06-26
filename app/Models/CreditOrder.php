<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditOrder extends Model
{
    protected $fillable = [
        'order_id',
        'bill_id',
        'credit_account_id',
        'credit_account_user_id',
        'used_by_name',
        'used_by_phone',
        'credit_reference',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'approved_by',
        'approved_at',
        'due_date',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'due_date' => 'datetime',
    ];

    public function account() { return $this->belongsTo(CreditAccount::class, 'credit_account_id'); }
    public function authorizedUser() { return $this->belongsTo(CreditAccountUser::class, 'credit_account_user_id'); }
    public function creditAccountUser() { return $this->belongsTo(CreditAccountUser::class, 'credit_account_user_id'); }
    public function authorizedUsers() { return $this->hasMany(CreditOrderAuthorizedUser::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function bill() { return $this->belongsTo(Bill::class); }
    public function settlements() { return $this->hasMany(CreditSettlement::class); }
    public function logs() { return $this->hasMany(CreditApprovalLog::class); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
