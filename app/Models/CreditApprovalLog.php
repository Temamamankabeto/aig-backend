<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditApprovalLog extends Model
{
    protected $fillable = ['credit_order_id','action','actor_id','note'];
    public function creditOrder() { return $this->belongsTo(CreditOrder::class); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
