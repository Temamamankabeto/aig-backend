<?php

namespace App\Models;

use App\Services\InventoryDeductionService;
use App\Models\CreditAgreement;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'order_type',
        'table_id',
        'created_by',
        'waiter_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'rider_id',
        'delivery_fee',
        'status',
        'payment_type',
        'payment_status',
        'payment_method',
        'credit_status',
        'credit_account_id',
        'credit_agreement_id',
        'credit_order_mode',
        'meal_type',
        'number_of_person',
        'customer_tin',
        'bill_printed_at',
        'subtotal',
        'tax',
        'service_charge',
        'discount',
        'total',
        'paid_amount',
        'change_amount',
        'notes',
        'ordered_at',
        'confirmed_at',
        'completed_at',
        'paid_at',
        'payment_received_by',
        'dispatched_at',
        'delivered_at',
        'served_at',
        'cancel_requested_at',
        'cancel_requested_by',
        'cancel_request_reason',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'ordered_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'served_at' => 'datetime',
        'completed_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'voided_at' => 'datetime',
        'credit_account_id' => 'integer',
        'credit_agreement_id' => 'integer',
        'number_of_person' => 'integer',
        'bill_printed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updated(function (Order $order) {
            if (! $order->wasChanged('status')) {
                return;
            }

            if (! in_array($order->status, ['cancelled', 'void'], true)) {
                return;
            }

            $userId = auth()->check() ? (int) auth()->id() : null;

            app(InventoryDeductionService::class)->restoreForOrder($order->fresh(['items.menuItem']), $userId);
        });
    }

    public function items() { return $this->hasMany(OrderItem::class); }
    public function table() { return $this->belongsTo(DiningTable::class, 'table_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function waiter() { return $this->belongsTo(User::class, 'waiter_id'); }
    public function bill() { return $this->hasOne(Bill::class, 'order_id'); }
    public function creditAccount() { return $this->belongsTo(CreditAccount::class, 'credit_account_id'); }
    public function creditAgreement() { return $this->belongsTo(CreditAgreement::class, 'credit_agreement_id'); }
    public function creditOrder() { return $this->hasOne(CreditOrder::class, 'order_id'); }
}
