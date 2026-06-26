<?php

namespace App\Models;

use App\Support\TableStatusHelper;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    protected $table = 'dining_tables';

    protected $fillable = [
        'table_number',
        'name',
        'capacity',
        'section',
        'status',
        'assigned_waiter_id',
        'is_active',
        'is_public',
        'sort_order',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function waiter()
    {
        return $this->belongsTo(User::class, 'assigned_waiter_id');
    }

    public function waiters()
    {
        return $this->belongsToMany(
            User::class,
            'dining_table_waiters',
            'dining_table_id',
            'user_id'
        )->select('users.id', 'users.name')->withTimestamps();
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return (string) ($this->name ?: ('Table ' . $this->table_number));
    }

    public function getOperationalStatusAttribute(): string
    {
        return TableStatusHelper::operationalStatus($this);
    }
}
