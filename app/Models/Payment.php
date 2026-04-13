<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id', 'method', 'amount', 'reference','receipt_path',
        'status', 'received_by', 'paid_at',
        'cash_shift_id',
        'screenshot_path',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

   public function receivedBy()
   {
   return $this->belongsTo(User::class, 'received_by');
   }
   public function receiver()
   {
   return $this->belongsTo(User::class, 'received_by');
   }

    public function refundRequest()
    {
        return $this->hasOne(RefundRequest::class, 'payment_id');
    }
        public function cashShift()
        {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
        }

    
}