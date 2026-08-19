<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentStockConsumption extends Model
{
    protected $fillable = ['inventory_transaction_id', 'inventory_item_id', 'department_id', 'recorded_by', 'quantity', 'unit_cost', 'total_cost', 'note', 'approval_status', 'approved_by', 'approved_at', 'approval_batch', 'consumed_at'];
    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:3', 'total_cost' => 'decimal:2', 'consumed_at' => 'datetime', 'approved_at' => 'datetime'];
    public function issue() { return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id'); }
    public function inventoryItem() { return $this->belongsTo(InventoryItem::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
