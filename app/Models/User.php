<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

protected $guard_name = 'sanctum';

protected $fillable=[
'name','email','phone','profile_image',

'password','is_active','address',
];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active'=> 'boolean',
        
    ];

    // Dining tables assigned to this waiter
    

    // Orders created by this user (customer/waiter)
    public function createdOrders()
    {
        return $this->hasMany(Order::class, 'created_by');
    }



    // Orders served/managed by waiter
    public function waiterOrders()
    {
        return $this->hasMany(Order::class, 'waiter_id');
    }

    public function kitchenTickets()
    {
        return $this->hasMany(KitchenTicket::class, 'chef_id');
    }

    public function barTickets()
    {
        return $this->hasMany(BarTicket::class, 'barman_id');
    }

    public function issuedBills()
    {
        return $this->hasMany(Bill::class, 'issued_by');
    }

    public function receivedPayments()
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function cashShifts()
    {
        return $this->hasMany(CashShift::class, 'cashier_id');
    }

    public function tables()
    {
    return $this->hasMany(DiningTable::class, 'assigned_waiter_id');
    }

    protected $appends = ['profile_image_url'];

    public function getProfileImageUrlAttribute()
    {
    return $this->profile_image
    ? asset('storage/' . $this->profile_image)
    : null;
    }
}