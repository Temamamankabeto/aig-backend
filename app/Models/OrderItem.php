<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id','menu_item_id','quantity','unit_price','line_total',
        'station','item_status','notes','modifiers',
        'started_at','ready_at','served_at','inventory_deducted_at'
    ];

    
   protected $casts = [
   'quantity' => 'decimal:2',
   'unit_price' => 'decimal:2',
   'line_total' => 'decimal:2',
   'modifiers' => 'array',
   'started_at' => 'datetime',
   'ready_at' => 'datetime',
   'served_at' => 'datetime',
   'inventory_deducted_at' => 'datetime',
   ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function kitchenTicket()
    {
        return $this->hasOne(KitchenTicket::class, 'order_item_id');
    }

    public function barTicket()
    {
        return $this->hasOne(BarTicket::class, 'order_item_id');
    }
}