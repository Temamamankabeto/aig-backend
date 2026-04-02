<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PublicPaymentController extends Controller
{
    public function showBill($id)
    {
        $bill = Bill::with([
            'order',
            'order.items.menuItem',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $bill->id,
                'status' => $bill->status,
                'subtotal' => (float) $bill->subtotal,
                'tax' => (float) $bill->tax,
                'service_charge' => (float) $bill->service_charge,
                'discount' => (float) $bill->discount,
                'total' => (float) $bill->total,
                'paid_amount' => (float) $bill->paid_amount,
                'balance' => (float) $bill->balance,
                'issued_at' => $bill->issued_at,
                'order' => $bill->order,
            ],
        ]);
    }

    public function storePayment(Request $request, $id)
    {
        $bill = Bill::with('order')->findOrFail($id);

        if (!$bill->order) {
            return response()->json([
                'success' => false,
                'message' => 'Bill has no related order.',
            ], 422);
        }

        if ((float) $bill->balance <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This bill is already fully paid.',
            ], 422);
        }

        $existingSubmittedPayment = Payment::where('bill_id', $bill->id)
            ->whereIn('status', ['submitted', 'pending', 'processing', 'paid'])
            ->latest('id')
            ->first();

        if ($existingSubmittedPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment has already been submitted for this bill and is awaiting verification.',
                'data' => [
                    'payment_id' => $existingSubmittedPayment->id,
                    'payment_status' => $existingSubmittedPayment->status,
                ],
            ], 422);
        }

        $validated = $request->validate([
            'method' => 'required|in:cash,transfer',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);

        if ((float) $validated['amount'] > (float) $bill->balance) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount cannot be greater than bill balance.',
            ], 422);
        }

        if (
            $validated['method'] === 'transfer' &&
            !$request->filled('reference') &&
            !$request->hasFile('receipt')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Reference or receipt is required for transfer payment.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated, $bill) {
            $receiptPath = null;

            if ($request->hasFile('receipt')) {
                $receiptPath = $request->file('receipt')->store('receipts', 'public');
            }

            $payment = Payment::create([
                'bill_id' => $bill->id,
                'method' => $validated['method'],
                'amount' => (float) $validated['amount'],
                'reference' => $validated['reference'] ?? null,
                'receipt_path' => $receiptPath,
                'status' => 'submitted',
                'received_by' => auth()->id() ?? 1,
                'paid_at' => now(),
            ]);

            if ($bill->issued_at === null) {
                $bill->issued_by = auth()->id() ?? $bill->issued_by;
                $bill->issued_at = now();
            }

            if (in_array($bill->status, ['draft', 'issued', 'pending'], true)) {
                $bill->status = 'submitted';
            }

            $bill->save();

            $freshBill = $bill->fresh();

            return response()->json([
                'success' => true,
                'message' => 'Payment submitted successfully and is awaiting verification.',
                'data' => [
                    'payment' => $payment,
                    'bill' => [
                        'id' => $freshBill->id,
                        'status' => $freshBill->status,
                        'total' => (float) $freshBill->total,
                        'paid_amount' => (float) $freshBill->paid_amount,
                        'balance' => (float) $freshBill->balance,
                    ],
                    'receipt_url' => $receiptPath ? Storage::url($receiptPath) : null,
                ],
            ], 201);
        });
    }
}