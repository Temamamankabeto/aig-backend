<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockReceiving extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'status',
        'received_by', 'approved_by',
        'note', 'received_at', 'approved_at',
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
}