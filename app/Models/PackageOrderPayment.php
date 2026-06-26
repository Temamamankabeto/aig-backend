<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageOrderPayment extends Model
{
    protected $fillable = ['package_order_id','amount','payment_method','reference_number','received_by','paid_at','notes'];
    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime'];

    public function packageOrder() { return $this->belongsTo(PackageOrder::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
