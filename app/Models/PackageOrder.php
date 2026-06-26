<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageOrder extends Model
{
    protected $fillable = [
        'package_id','customer_id','organization_id','package_order_number','event_name','event_type','guest_count',
        'actual_guests','event_date','event_time','start_time','end_time','venue_section','service_style',
        'delivery_location','allergen_notes','vegetarian_count','special_notes','service_status','service_progress_note','delay_reason',
        'status','payment_type','credit_status','subtotal','tax','service_charge','discount','total','paid_amount','balance','deposit_required','notes','created_by',
        'approved_by','approved_at','completed_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'subtotal' => 'decimal:2','tax' => 'decimal:2','service_charge' => 'decimal:2','discount' => 'decimal:2',
        'total' => 'decimal:2','paid_amount' => 'decimal:2','balance' => 'decimal:2','deposit_required' => 'decimal:2',
        'approved_at' => 'datetime','completed_at' => 'datetime',
    ];

    public function package() { return $this->belongsTo(Package::class); }
    public function items() { return $this->hasMany(PackageOrderItem::class); }
    public function schedule() { return $this->hasOne(PackageOrderSchedule::class); }
    public function payments() { return $this->hasMany(PackageOrderPayment::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
