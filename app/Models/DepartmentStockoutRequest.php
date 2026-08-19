<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentStockoutRequest extends Model
{
    protected $fillable = ['request_number', 'department_id', 'inventory_item_id', 'quantity', 'reason', 'status', 'requested_by', 'requested_at', 'validated_by', 'validated_at', 'validation_note', 'issued_by', 'issued_at', 'inventory_transaction_id'];
    protected $casts = ['quantity' => 'decimal:3', 'requested_at' => 'datetime', 'validated_at' => 'datetime', 'issued_at' => 'datetime'];
    public function department() { return $this->belongsTo(Department::class); }
    public function inventoryItem() { return $this->belongsTo(InventoryItem::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function validator() { return $this->belongsTo(User::class, 'validated_by'); }
    public function issuer() { return $this->belongsTo(User::class, 'issued_by'); }
    public function inventoryTransaction() { return $this->belongsTo(InventoryTransaction::class); }
}
