<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryItemBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'inventory_item_batches';

    protected $fillable = [
        'inventory_item_id',
        'purchase_price',
        'initial_qty',
        'remaining_qty',
        'expiry_date',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'initial_qty' => 'decimal:3',
        'remaining_qty' => 'decimal:3',
        'expiry_date' => 'date',
    ];

    /**
     * Relationship: Batch belongs to Inventory Item
     */
    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Helper: Check if batch is expired
     */
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Helper: Check if batch still has stock
     */
    public function hasStock(): bool
    {
        return $this->remaining_qty > 0;
    }
}