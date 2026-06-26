<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReceivingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_receiving_id',
        'purchase_order_item_id',
        'inventory_item_id',
        'quantity_received',
        'base_unit',
        'unit_cost',
        'expiry_date',
        'batch_note',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function stockReceiving()
    {
        return $this->belongsTo(StockReceiving::class, 'stock_receiving_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
