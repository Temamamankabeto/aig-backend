<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'bill_number', 'status', 'bill_type', 'credit_status',
        'subtotal', 'tax', 'service_charge', 'discount', 'total',
        'issued_by', 'issued_at',
        'paid_amount', 'balance', 'paid_at', 'due_date',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'due_date' => 'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class, 'order_id'); }
    public function issuer() { return $this->belongsTo(User::class, 'issued_by'); }
    public function payments() { return $this->hasMany(Payment::class, 'bill_id'); }
    public function creditOrder() { return $this->hasOne(CreditOrder::class, 'bill_id'); }
}
