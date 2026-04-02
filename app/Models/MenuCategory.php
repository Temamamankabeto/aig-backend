<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MenuCategory extends Model
{
    use HasFactory;
protected $table = 'menu_categories';
   protected $fillable = ['name','type','icon','description','sort_order','is_active', 'image_path'];

    protected $casts = [
          'is_active' => 'boolean',
          'sort_order' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(MenuItem::class, 'category_id');
    }

        public function activeItems()
        {
        return $this->hasMany(MenuItem::class, 'category_id')
        ->where('is_active', true)
        ->where('is_available', true);
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