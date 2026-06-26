<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageItem extends Model
{
    protected $fillable = ['package_id', 'menu_item_id', 'quantity_per_person'];
    protected $casts = ['quantity_per_person' => 'decimal:3'];

    public function package() { return $this->belongsTo(Package::class); }
    public function menuItem() { return $this->belongsTo(MenuItem::class); }
}
