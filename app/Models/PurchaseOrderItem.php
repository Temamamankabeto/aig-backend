<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'inventory_item_id',
        'quantity', 'base_unit', 'received_quantity', 'unit_cost', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected $appends = ['remaining_quantity'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function receivingItems()
    {
        return $this->hasMany(StockReceivingItem::class, 'purchase_order_item_id');
    }

    public function getRemainingQuantityAttribute(): string
    {
        return number_format(max(0, (float) $this->quantity - (float) ($this->received_quantity ?? 0)), 3, '.', '');
    }
}
