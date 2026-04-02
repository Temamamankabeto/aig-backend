<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'order_type',
        'table_id',
        'created_by',
        'waiter_id',
        'customer_name','
        customer_phone',
        'customer_address',
        'rider_id',
        'delivery_fee',
        'status',
        'subtotal',
        'tax',
        'service_charge',
        'discount','total',
        'notes','ordered_at',
        'confirmed_at',
        'completed_at',
        'dispatched_at',
        'delivered_at',
        'served_at',
       
        'cancel_requested_at',
        'cancel_requested_by',
        'cancel_request_reason',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
    'subtotal' => 'decimal:2',
    'tax' => 'decimal:2',
    'service_charge' => 'decimal:2',
    'delivery_fee' => 'decimal:2',
    'discount' => 'decimal:2',
    'total' => 'decimal:2',
    'ordered_at' => 'datetime',
    'confirmed_at' => 'datetime',
    'dispatched_at' => 'datetime',
    'delivered_at' => 'datetime',
    'served_at' => 'datetime',
    'completed_at' => 'datetime',
      'cancel_requested_at' => 'datetime',
      'voided_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function waiter()
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }
    public function bill()
    {
    return $this->hasOne(Bill::class, 'order_id');
    }
}