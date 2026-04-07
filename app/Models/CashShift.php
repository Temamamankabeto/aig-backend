<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_id',
        'opened_at', 'closed_at',
        'opening_cash', 'closing_cash',
        'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'closing_cash' => 'decimal:2',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'cash_shift_id');
    }

    public function movements()
{
    return $this->hasMany(CashShiftMovement::class, 'cash_shift_id');
}
}
