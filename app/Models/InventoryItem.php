<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'sku', 'category', 'unit',
        'quantity', 'reorder_level', 'unit_cost',
        'expiry_date', 'is_active',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id');
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class, 'inventory_item_id');
    }
}