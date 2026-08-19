<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinancePaymentController extends Controller
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('payments.read'), 403, 'You are not authorized to view cashier payments.');

        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'finance_status' => 'nullable|in:pending,approved,received',
            'method' => 'nullable|in:cash,card,mobile,transfer,bank',
            'search' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Payment::query()
            ->with(['bill.order', 'order', 'receiver', 'financeReceiver'])
            ->where('status', 'paid')
            ->whereHas('receiver.roles', fn ($role) => $role->whereRaw('LOWER(name) = ?', ['cashier']))
            ->latest('paid_at');

        if (! empty($validated['date_from'])) $query->whereDate('paid_at', '>=', $validated['date_from']);
        if (! empty($validated['date_to'])) $query->whereDate('paid_at', '<=', $validated['date_to']);
        if (! empty($validated['finance_status'])) $query->where('finance_status', $validated['finance_status']);
        if (! empty($validated['method'])) $query->where('method', $validated['method']);
        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('bill', fn ($bill) => $bill->where('bill_number', 'like', "%{$search}%"));
            });
        }

        $summaryQuery = clone $query;
        $rows = $query->paginate($validated['per_page'] ?? 20);
        $rows->getCollection()->transform(function (Payment $payment) {
            $payment->finance_receipt_url = $payment->finance_receipt_path ? Storage::disk('public')->url($payment->finance_receipt_path) : null;
            return $payment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Cashier payments fetched successfully.',
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(), 'total' => $rows->total(),
                'summary' => [
                    'total_amount' => round((float) (clone $summaryQuery)->sum('amount'), 2),
                    'pending_count' => (clone $summaryQuery)->where('finance_status', 'pending')->count(),
                    'pending_amount' => round((float) (clone $summaryQuery)->where('finance_status', 'pending')->sum('amount'), 2),
                    'approved_amount' => round((float) (clone $summaryQuery)->whereIn('finance_status', ['approved', 'received'])->sum('amount'), 2),
                ],
            ],
        ]);
    }

    public function markReceived(Request $request, $id)
    {
        abort_unless($request->user()?->can('payments.read'), 403, 'You are not authorized to receive cashier payments.');
        $validated = $request->validate([
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'note' => 'nullable|string|max:1000',
        ]);

        $path = $request->file('receipt')->store('finance/payment-receipts', 'public');

        try {
            return DB::transaction(function () use ($request, $validated, $id, $path) {
                $payment = Payment::with('receiver.roles')->lockForUpdate()->findOrFail($id);
                abort_unless($payment->receiver?->hasRole('Cashier'), 422, 'Only payments recorded by a cashier can be received by Finance.');
                abort_if($payment->status !== 'paid', 422, 'Only completed customer payments can be received by Finance.');
                abort_if(in_array($payment->finance_status, ['approved', 'received'], true), 422, 'This payment was already approved by Finance.');

                $before = $payment->toArray();
                $payment->update([
                    'finance_status' => 'approved',
                    'finance_receipt_path' => $path,
                    'finance_received_by' => $request->user()->id,
                    'finance_received_at' => now(),
                    'finance_note' => $validated['note'] ?? null,
                ]);
                $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'finance_payment_received', $before, $payment->fresh()->toArray(), 'Finance received cashier payment and uploaded bank receipt.');

                $payment = $payment->fresh(['bill.order', 'order', 'receiver', 'financeReceiver']);
                $payment->finance_receipt_url = Storage::disk('public')->url($payment->finance_receipt_path);
                return response()->json(['success' => true, 'message' => 'Payment approved successfully.', 'data' => $payment, 'meta' => null]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    public function approveBulk(Request $request)
    {
        abort_unless($request->user()?->can('payments.read'), 403, 'You are not authorized to approve cashier payments.');
        $validated = $request->validate([
            'selection_mode' => 'required|in:selected,filtered',
            'payment_ids' => 'required_if:selection_mode,selected|array|min:1',
            'payment_ids.*' => 'integer|distinct|exists:payments,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'method' => 'nullable|in:cash,card,mobile,transfer,bank',
            'search' => 'nullable|string|max:120',
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'note' => 'nullable|string|max:1000',
        ]);

        $path = $request->file('receipt')->store('finance/payment-receipts', 'public');

        try {
            return DB::transaction(function () use ($request, $validated, $path) {
                $query = Payment::query()
                    ->with('receiver.roles')
                    ->where('status', 'paid')
                    ->where('finance_status', 'pending')
                    ->whereHas('receiver.roles', fn ($role) => $role->whereRaw('LOWER(name) = ?', ['cashier']));

                if ($validated['selection_mode'] === 'selected') {
                    $query->whereIn('id', $validated['payment_ids']);
                } else {
                    if (! empty($validated['date_from'])) $query->whereDate('paid_at', '>=', $validated['date_from']);
                    if (! empty($validated['date_to'])) $query->whereDate('paid_at', '<=', $validated['date_to']);
                    if (! empty($validated['method'])) $query->where('method', $validated['method']);
                    if (! empty($validated['search'])) {
                        $search = trim($validated['search']);
                        $query->where(function ($builder) use ($search) {
                            $builder->where('reference', 'like', "%{$search}%")
                                ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%"))
                                ->orWhereHas('bill', fn ($bill) => $bill->where('bill_number', 'like', "%{$search}%"));
                        });
                    }
                }

                $payments = $query->lockForUpdate()->get();
                abort_if($payments->isEmpty(), 422, 'No pending cashier payments matched the selection.');
                if ($validated['selection_mode'] === 'selected') {
                    abort_if($payments->count() !== count($validated['payment_ids']), 422, 'One or more selected payments are no longer pending. Refresh and select again.');
                }

                foreach ($payments as $payment) {
                    $before = $payment->toArray();
                    $payment->update([
                        'finance_status' => 'approved',
                        'finance_receipt_path' => $path,
                        'finance_received_by' => $request->user()->id,
                        'finance_received_at' => now(),
                        'finance_note' => $validated['note'] ?? null,
                    ]);
                    $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'finance_payment_approved', $before, $payment->fresh()->toArray(), 'Finance approved cashier payment in a bulk reconciliation.');
                }

                return response()->json([
                    'success' => true,
                    'message' => $payments->count() . ' cashier payment(s) approved successfully.',
                    'data' => ['approved_count' => $payments->count(), 'payment_ids' => $payments->pluck('id')->values()],
                    'meta' => null,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }
}
