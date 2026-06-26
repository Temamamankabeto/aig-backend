<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number', 'supplier_id', 'status', 'total',
        'created_by', 'submitted_by', 'approved_by', 'cancelled_by',
        'submitted_at', 'approved_at', 'received_at', 'cancelled_at',
        'expected_date', 'notes', 'cancel_reason', 'reference_source',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expected_date' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function receivings()
    {
        return $this->hasMany(StockReceiving::class, 'purchase_order_id');
    }

    public function receiving()
    {
        return $this->hasOne(StockReceiving::class, 'purchase_order_id')->latestOfMany();
    }

    public function histories()
    {
        return $this->hasMany(PurchaseOrderStatusHistory::class, 'purchase_order_id');
    }
}
