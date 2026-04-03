<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'menu_mode', // normal | spatial
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

    protected $attributes = [
        'views_count' => 0,
        'is_featured' => false,
        'menu_mode' => 'normal',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only active + available items
     */
    public function scopeAvailable($query)
    {
        return $query
            ->where('is_available', true)
            ->where('is_active', true);
    }

    /**
     * Filter by type (food / drink)
     */
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

    /**
     * Only normal menu items
     */
    public function scopeNormal($query)
    {
        return $query->where('menu_mode', 'normal');
    }

    /**
     * Only spatial menu items
     */
    public function scopeSpatial($query)
    {
        return $query->where('menu_mode', 'spatial');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2);
    }

    /**
     * Full image URL
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? url('storage/' . $this->image_path)
            : null;
    }
}