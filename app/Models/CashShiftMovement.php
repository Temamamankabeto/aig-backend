<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashShiftMovement extends Model
{
    protected $fillable = [
        'cash_shift_id',
        'created_by',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}