<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class KitchenTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'chef_id',
        'status',
        'estimated_minutes',
        'delay_reason',
        'rejection_reason',
        'prep_note',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'estimated_minutes' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function chef()
    {
        return $this->belongsTo(User::class, 'chef_id');
    }
}