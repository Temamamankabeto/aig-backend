<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id', 'order_id', 'method', 'amount', 'reference','receipt_path',
        'status', 'received_by', 'paid_at',
        'cash_shift_id',
        'screenshot_path',
        'finance_status', 'finance_receipt_path', 'finance_received_by',
        'finance_received_at', 'finance_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'finance_received_at' => 'datetime',
        
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function order() { return $this->belongsTo(Order::class, 'order_id'); }
    public function financeReceiver() { return $this->belongsTo(User::class, 'finance_received_by'); }

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
        return $this->hasOne(RefundRequest::class, 'payment_id')->latestOfMany();
    }

    public function refundRequests() { return $this->hasMany(RefundRequest::class, 'payment_id'); }
        public function cashShift()
        {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
        }

    
}
