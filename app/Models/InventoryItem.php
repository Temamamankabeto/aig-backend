<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InventoryItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'unit',
        'minimum_quantity',
        'current_stock',
        'average_purchase_price',
    ];

    protected $casts = [
        'minimum_quantity' => 'decimal:3',
        'current_stock' => 'decimal:3',
        'average_purchase_price' => 'decimal:3',
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