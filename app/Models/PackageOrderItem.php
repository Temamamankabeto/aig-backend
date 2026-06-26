<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageOrderItem extends Model
{
    protected $fillable = ['package_order_id','menu_item_id','quantity','unit_price','total_price'];
    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'total_price' => 'decimal:2'];

    public function packageOrder() { return $this->belongsTo(PackageOrder::class); }
    public function menuItem() { return $this->belongsTo(MenuItem::class); }
}
