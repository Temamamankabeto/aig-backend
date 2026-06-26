<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'type',
        'price',
        'image_path',
        'is_available',
        'is_active',
        'modifiers',
        'prep_minutes',
        'views_count',
        'is_featured',
        'menu_mode',
        'has_ingredients',
        'inventory_tracking_mode',
        'direct_inventory_item_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_available' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'has_ingredients' => 'boolean',
        'modifiers' => 'array',
        'prep_minutes' => 'integer',
        'views_count' => 'integer',
    ];

    protected $attributes = [
        'views_count' => 0,
        'is_featured' => false,
        'menu_mode' => 'normal',
        'has_ingredients' => true,
        'inventory_tracking_mode' => 'recipe',
    ];

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'menu_item_id');
    }

    public function recipe()
    {
        return $this->hasOne(Recipe::class, 'menu_item_id');
    }

    public function directInventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'direct_inventory_item_id');
    }

    public function scopeAvailable($query)
    {
        return $query
            ->where('is_available', true)
            ->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeFood($query)
    {
        return $query->where('type', 'food');
    }

    public function scopeDrink($query)
    {
        return $query->where('type', 'drink');
    }

    public function scopeNormal($query)
    {
        return $query->where('menu_mode', 'normal');
    }

    public function scopeSpatial($query)
    {
        return $query->where('menu_mode', 'spatial');
    }

    public function scopeWithIngredients($query)
    {
        return $query->where('has_ingredients', true);
    }

    public function scopeWithoutIngredients($query)
    {
        return $query->where('has_ingredients', false);
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? url('storage/' . $this->image_path)
            : null;
    }
}
