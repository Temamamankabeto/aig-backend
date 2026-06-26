<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageOrderSchedule extends Model
{
    protected $fillable = ['package_order_id','prep_start_time','ready_time','delivery_time','assigned_team','status','notes'];
    protected $casts = ['prep_start_time' => 'datetime', 'ready_time' => 'datetime', 'delivery_time' => 'datetime'];

    public function packageOrder() { return $this->belongsTo(PackageOrder::class); }
}
