<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'is_active',
        'tax_id', 'credit_days', 'contract_terms', 'delivery_performance_notes', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_days' => 'integer',
    ];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }
}
