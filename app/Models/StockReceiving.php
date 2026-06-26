<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReceiving extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'status',
        'received_by', 'approved_by',
        'note', 'delivery_note_path', 'received_at', 'approved_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(StockReceivingItem::class, 'stock_receiving_id');
    }
}
