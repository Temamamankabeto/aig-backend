<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundRequestController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', RefundRequest::class);
        $q = RefundRequest::query()->with(['payment.bill.order', 'requester', 'approver'])->latest('id');
        if ($request->filled('status')) $q->where('status', $request->string('status'));
        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
    }

    public function show($id)
    {
        $row = RefundRequest::with(['payment.bill.order.items.menuItem', 'requester', 'approver'])->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request, $paymentId)
    {
        $this->authorize('create', RefundRequest::class);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $paymentId, $data) {
            $payment = Payment::with('refundRequest', 'bill.order')->lockForUpdate()->findOrFail($paymentId);
            if ($payment->status !== 'paid') return response()->json(['success' => false, 'message' => 'Only paid payments can be refunded'], 422);
            if ($payment->refundRequest && !in_array($payment->refundRequest->status, ['rejected'], true)) return response()->json(['success' => false, 'message' => 'A refund request already exists for this payment'], 422);
            $amount = round((float) $data['amount'], 2);
            if ($amount > (float) $payment->amount) return response()->json(['success' => false, 'message' => 'Refund amount exceeds payment amount'], 422);

            $rr = RefundRequest::create([
                'payment_id' => $payment->id,
                'status' => 'requested',
                'amount' => $amount,
                'reason' => $data['reason'],
                'requested_by' => $request->user()->id,
                'requested_at' => now(),
            ]);

            $this->notificationService->notifyUsersByPermission('refunds.approve', 'Refund requested', "Refund requested for payment #{$payment->id}.", ['kind' => 'refund_requested', 'refund_request_id' => $rr->id, 'payment_id' => $payment->id, 'order_id' => $payment->bill?->order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $rr->id, 'refund_requested', null, $rr->toArray(), 'Refund requested.');
            return response()->json(['success' => true, 'data' => $rr], 201);
        });
    }

    public function approve(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $rr = RefundRequest::with('payment.bill.order')->lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $rr);
            if ($rr->status !== 'requested') return response()->json(['success' => false, 'message' => 'Refund request is not pending'], 422);
            $before = $rr->toArray();
            $rr->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->notificationService->notifyUser($rr->requested_by, 'Refund approved', "Refund request #{$rr->id} was approved.", ['kind' => 'refund_approved', 'refund_request_id' => $rr->id, 'payment_id' => $rr->payment_id, 'order_id' => $rr->payment?->bill?->order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $rr->id, 'refund_approved', $before, $rr->fresh()->toArray(), 'Refund approved.');
            return response()->json(['success' => true, 'data' => $rr]);
        });
    }

    public function reject(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $rr = RefundRequest::with('payment.bill.order')->lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $rr);
            if ($rr->status !== 'requested') return response()->json(['success' => false, 'message' => 'Refund request is not pending'], 422);
            $before = $rr->toArray();
            $rr->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->notificationService->notifyUser($rr->requested_by, 'Refund rejected', "Refund request #{$rr->id} was rejected.", ['kind' => 'refund_rejected', 'refund_request_id' => $rr->id, 'payment_id' => $rr->payment_id, 'order_id' => $rr->payment?->bill?->order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $rr->id, 'refund_rejected', $before, $rr->fresh()->toArray(), 'Refund rejected.');
            return response()->json(['success' => true, 'data' => $rr]);
        });
    }

    public function processRefund(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $rr = RefundRequest::with(['payment.bill.order'])->lockForUpdate()->findOrFail($id);
            $this->authorize('processRefund', $rr);
            if ($rr->status !== 'approved') return response()->json(['success' => false, 'message' => 'Refund must be approved first'], 422);

            $payment = $rr->payment;
            $bill = $payment->bill;
            $amount = round((float) $rr->amount, 2);
            $beforeBill = $bill->toArray();
            $beforePayment = $payment->toArray();
            $beforeRefund = $rr->toArray();

            $payment->status = 'refunded';
            $payment->save();

            $bill->paid_amount = max(0, round((float) $bill->paid_amount - $amount, 2));
            $bill->balance = max(0, round((float) $bill->total - (float) $bill->paid_amount, 2));
            if ((float) $bill->paid_amount <= 0) {
                $bill->status = 'issued';
                $bill->paid_at = null;
            } elseif ((float) $bill->balance > 0) {
                $bill->status = 'partial';
                $bill->paid_at = null;
            }
            $bill->save();

            $rr->update(['status' => 'processed', 'processed_at' => now()]);

            $order = $bill->order;
            $this->notificationService->notifyUser($order?->waiter_id, 'Refund processed', "Refund for order {$order?->order_number} was processed.", ['kind' => 'refund_processed', 'refund_request_id' => $rr->id, 'payment_id' => $payment->id, 'order_id' => $order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_refunded', $beforePayment, $payment->fresh()->toArray(), 'Payment refunded.');
            $this->auditLogger->log($request, $request->user()->id, 'Bill', $bill->id, 'bill_refund_applied', $beforeBill, $bill->fresh()->toArray(), 'Refund applied to bill.');
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $rr->id, 'refund_processed', $beforeRefund, $rr->fresh()->toArray(), 'Refund processed.');

            return response()->json(['success' => true, 'data' => $rr->fresh(['payment.bill'])]);
        });
    }
}
