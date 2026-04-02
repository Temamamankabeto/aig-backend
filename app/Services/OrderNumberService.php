<?php

namespace App\Services;

use App\Models\Order;

class OrderNumberService
{
    public function generate(): string
    {
        $datePrefix = now()->format('Ymd');

        $lastOrder = Order::query()
            ->where('order_number', 'like', "AIG-{$datePrefix}-%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($lastOrder && preg_match('/^AIG-\d{8}-(\d{4})$/', $lastOrder->order_number, $matches)) {
            $nextSequence = str_pad(((int) $matches[1]) + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $nextSequence = '0001';
        }

        return "AIG-{$datePrefix}-{$nextSequence}";
    }
}