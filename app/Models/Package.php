<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['name', 'description', 'price_per_person', 'minimum_people', 'is_active'];
    protected $casts = ['price_per_person' => 'decimal:2', 'minimum_people' => 'integer', 'is_active' => 'boolean'];

    public function items() { return $this->hasMany(PackageItem::class); }
    public function menuItems() { return $this->belongsToMany(MenuItem::class, 'package_items')->withPivot('quantity_per_person')->withTimestamps(); }
    public function orders() { return $this->hasMany(PackageOrder::class); }
}
