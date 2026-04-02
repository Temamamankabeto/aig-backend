<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    protected $table = 'dining_tables';

    protected $fillable = [
        'table_number',
        'capacity',
        'section',
        'status',
        'assigned_waiter_id',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function waiter()
    {
        return $this->belongsTo(User::class, 'assigned_waiter_id');
    }

    public function waiters()
    {
    return $this->belongsToMany(
    \App\Models\User::class,
    'dining_table_waiters',
    'dining_table_id',
    'user_id'
    )->select('users.id','users.name')->withTimestamps();
    }
     public function orders()
     {
     return $this->hasMany(Order::class, 'table_id');
     }
     
}
