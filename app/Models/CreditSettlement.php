<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditSettlement extends Model
{
    protected $fillable = ['credit_order_id','amount','payment_method','reference_number','received_by','settled_at','notes'];
    protected $casts = ['amount' => 'decimal:2', 'settled_at' => 'datetime'];
    public function creditOrder() { return $this->belongsTo(CreditOrder::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
