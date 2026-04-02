<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MenuItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'description', 'type',
        'price', 'image_path', 'is_available', 'is_active',
        'modifiers', 'prep_minutes',
        'views_count',
        'is_featured',
    ];

    protected $casts = [
         'price' => 'decimal:2',
         'is_available' => 'boolean',
         'is_active' => 'boolean',
         'is_featured' => 'boolean',
         'modifiers' => 'array',
         'prep_minutes' => 'integer',
         'views_count' => 'integer',
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


    protected $attributes = [
    'views_count' => 0,
    'is_featured' => false,
    ];

   

    /**
    * Scope a query to only include available items.
    */
    public function scopeAvailable($query)
    {
    return $query->where('is_available', true)
    ->where('is_active', true);
    }

    /**
    * Scope a query to only include items of a given type.
    */
    public function scopeOfType($query, string $type)
    {
    return $query->where('type', $type);
    }

    /**
    * Scope a query to only include food items.
    */
    public function scopeFood($query)
    {
    return $query->where('type', 'food');
    }

    /**
    * Scope a query to only include drink items.
    */
    public function scopeDrink($query)
    {
    return $query->where('type', 'drink');
    }

    /**
    * Get formatted price
    */
    public function getFormattedPriceAttribute(): string
    {
    return number_format($this->price, 2);
    }

    /**
    * Get full image URL
    */
    public function getImageUrlAttribute(): ?string
    {
    return $this->image_path
    ? url('storage/' . $this->image_path)
    : null;
    }
}