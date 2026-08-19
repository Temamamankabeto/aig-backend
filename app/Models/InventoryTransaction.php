<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'unit_cost',
        'before_quantity',
        'after_quantity',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
        'responsible_user_id',
        'custody_status',
        'received_at',
        'used_quantity',
        'return_requested_quantity',
        'return_request_reason',
        'return_requested_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:3',
        'before_quantity' => 'decimal:3',
        'after_quantity' => 'decimal:3',
        'received_at' => 'datetime',
        'used_quantity' => 'decimal:3',
        'return_requested_quantity' => 'decimal:3',
        'return_requested_at' => 'datetime',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'reference_id')->withTrashed();
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
