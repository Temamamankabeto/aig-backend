<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceExpense extends Model
{
    protected $fillable = ['expense_number', 'category', 'description', 'amount', 'expense_date', 'reference', 'attachment_path', 'recorded_by'];
    protected $casts = ['amount' => 'decimal:2', 'expense_date' => 'date'];
    public function recorder() { return $this->belongsTo(User::class, 'recorded_by'); }
}
