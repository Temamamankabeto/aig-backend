<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RefundRequestController extends Controller
{
    public function __construct(private NotificationService $notificationService, private AuditLogger $auditLogger) {}

    private function query(): Builder
    {
        return RefundRequest::query()->with(['payment.bill.order', 'payment.order', 'payment.receiver', 'requester', 'approver', 'processor']);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', RefundRequest::class);
        $query = $this->query()->latest('requested_at');
        if ($request->user()->can('payments.refund.request') && ! $request->user()->can('payments.refund.approve')) $query->where('requested_by', $request->user()->id);
        $query->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('requested_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('requested_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn (Builder $n) => $n->where('id', 'like', $term)->orWhere('reason', 'like', $term)
                    ->orWhereHas('payment.order', fn (Builder $o) => $o->where('order_number', 'like', $term))
                    ->orWhereHas('payment.bill.order', fn (Builder $o) => $o->where('order_number', 'like', $term))
                    ->orWhereHas('requester', fn (Builder $u) => $u->where('name', 'like', $term)));
            });
        $page = $query->paginate(min(max((int) $request->input('per_page', 100), 1), 100));
        $page->getCollection()->transform(fn ($row) => $this->present($row));
        return response()->json(['success' => true, 'data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, $id)
    {
        $row = $this->query()->findOrFail($id); $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $this->present($row)]);
    }

    public function request(Request $request)
    {
        $data = $request->validate(['payment_id' => ['required', 'integer', 'exists:payments,id'], 'amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'max:1000']]);
        return $this->createRequest($request, (int) $data['payment_id'], $data);
    }

    public function store(Request $request, $paymentId)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'max:1000']]);
        return $this->createRequest($request, (int) $paymentId, $data);
    }

    private function createRequest(Request $request, int $paymentId, array $data)
    {
        $this->authorize('create', RefundRequest::class);
        return DB::transaction(function () use ($request, $paymentId, $data) {
            $payment = Payment::with(['bill.order', 'order'])->lockForUpdate()->findOrFail($paymentId);
            if (! in_array($payment->status, ['paid', 'refunded'], true)) return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded.'], 422);
            if (! $request->user()->can('payments.refund.approve') && (int) $payment->received_by !== (int) $request->user()->id) return response()->json(['success' => false, 'message' => 'You can request a refund only for a payment you recorded.'], 403);
            $reserved = (float) RefundRequest::where('payment_id', $payment->id)->whereIn('status', ['requested', 'approved', 'processed'])->lockForUpdate()->get(['amount'])->sum('amount');
            $amount = round((float) $data['amount'], 2); $available = round((float) $payment->amount - $reserved, 2);
            if ($amount > $available) return response()->json(['success' => false, 'message' => "Refund amount exceeds the available refundable amount of {$available}."], 422);
            $refund = RefundRequest::create(['payment_id' => $payment->id, 'status' => 'requested', 'amount' => $amount, 'reason' => trim($data['reason']), 'requested_by' => $request->user()->id, 'requested_at' => now()]);
            $order = $payment->order ?? $payment->bill?->order;
            $this->notificationService->notifyUsersByPermission('payments.refund.approve', 'Refund requested', "Refund requested for payment #{$payment->id}.", ['kind' => 'refund_requested', 'refund_request_id' => $refund->id, 'payment_id' => $payment->id, 'order_id' => $order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $refund->id, 'refund_requested', null, $refund->toArray(), 'Refund requested.');
            return response()->json(['success' => true, 'message' => 'Refund request submitted.', 'data' => $refund], 201);
        });
    }

    public function approve(Request $request, $id)
    {
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $refund = RefundRequest::lockForUpdate()->findOrFail($id); $this->authorize('approve', $refund);
            if ($refund->status !== 'requested') return response()->json(['success' => false, 'message' => 'Only requested refunds can be approved.'], 422);
            $before = $refund->toArray(); $refund->update(['status' => 'approved', 'decision_note' => $data['decision_note'] ?? null, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->notificationService->notifyUser($refund->requested_by, 'Refund approved', "Refund request #{$refund->id} was approved.", ['kind' => 'refund_approved', 'refund_request_id' => $refund->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $refund->id, 'refund_approved', $before, $refund->fresh()->toArray(), 'Refund approved.');
            return response()->json(['success' => true, 'message' => 'Refund approved.', 'data' => $refund->fresh()]);
        });
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:1000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $refund = RefundRequest::lockForUpdate()->findOrFail($id); $this->authorize('reject', $refund);
            if ($refund->status !== 'requested') return response()->json(['success' => false, 'message' => 'Only requested refunds can be rejected.'], 422);
            $before = $refund->toArray(); $refund->update(['status' => 'rejected', 'decision_note' => trim($data['decision_note']), 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $this->notificationService->notifyUser($refund->requested_by, 'Refund rejected', "Refund request #{$refund->id} was rejected.", ['kind' => 'refund_rejected', 'refund_request_id' => $refund->id]);
            $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $refund->id, 'refund_rejected', $before, $refund->fresh()->toArray(), 'Refund rejected.');
            return response()->json(['success' => true, 'message' => 'Refund rejected.', 'data' => $refund->fresh()]);
        });
    }

    public function processRefund(Request $request, $id)
    {
        $data = $request->validate(['refund_method' => ['required', 'in:cash,card,mobile,transfer,bank'], 'refund_reference' => ['nullable', 'string', 'max:255', 'required_unless:refund_method,cash'], 'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], 'decision_note' => ['nullable', 'string', 'max:1000']]);
        $proofPath = $request->file('proof')?->store('refund-proofs', 'public');
        try {
            return DB::transaction(function () use ($request, $id, $data, $proofPath) {
                $refund = RefundRequest::with(['payment.bill.order', 'payment.order'])->lockForUpdate()->findOrFail($id); $this->authorize('processRefund', $refund);
                if ($refund->status !== 'approved') return response()->json(['success' => false, 'message' => 'Refund must be approved before processing.'], 422);
                $payment = Payment::lockForUpdate()->findOrFail($refund->payment_id);
                $processed = (float) RefundRequest::where('payment_id', $payment->id)
                    ->where('status', 'processed')
                    ->where('id', '<>', $refund->id)
                    ->lockForUpdate()
                    ->get(['amount'])
                    ->sum('amount');
                $amount = round((float) $refund->amount, 2);
                if ($processed + $amount > (float) $payment->amount) return response()->json(['success' => false, 'message' => 'Processed refunds would exceed the original payment.'], 422);
                $beforePayment = $payment->toArray(); $beforeRefund = $refund->toArray(); $bill = null;
                $payment->status = round($processed + $amount, 2) >= round((float) $payment->amount, 2) ? 'refunded' : 'paid'; $payment->save();
                if ($payment->bill_id && ($bill = $payment->bill()->lockForUpdate()->first())) {
                    $bill->paid_amount = max(0, round((float) $bill->paid_amount - $amount, 2)); $bill->balance = max(0, round((float) $bill->total - (float) $bill->paid_amount, 2));
                    $bill->status = $bill->paid_amount <= 0 ? 'issued' : ($bill->balance > 0 ? 'partial' : 'paid'); if ($bill->status !== 'paid') $bill->paid_at = null; $bill->save();
                }
                $order = $payment->order()->lockForUpdate()->first() ?? $bill?->order;
                if ($order) {
                    // paid_amount previously included cash tendered, including change.
                    // Refund accounting must use the actual order total, not the tendered amount.
                    $remainingPaid = max(0, round((float) $order->total - ($processed + $amount), 2));
                    $order->paid_amount = $remainingPaid;
                    $order->payment_status = $remainingPaid <= 0 ? 'refunded' : 'partially_refunded';
                    if ($remainingPaid <= 0) {
                        $order->paid_at = null;
                    }
                    $order->save();
                }
                $refund->update(['status' => 'processed', 'refund_method' => $data['refund_method'], 'refund_reference' => $data['refund_reference'] ?? null, 'proof_path' => $proofPath, 'decision_note' => $data['decision_note'] ?? $refund->decision_note, 'processed_by' => $request->user()->id, 'processed_at' => now()]);
                $this->notificationService->notifyUser($refund->requested_by, 'Refund processed', "Refund request #{$refund->id} was processed.", ['kind' => 'refund_processed', 'refund_request_id' => $refund->id, 'order_id' => $order?->id]);
                $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_refunded', $beforePayment, $payment->fresh()->toArray(), 'Refund applied to payment.');
                $this->auditLogger->log($request, $request->user()->id, 'RefundRequest', $refund->id, 'refund_processed', $beforeRefund, $refund->fresh()->toArray(), 'Refund processed.');
                return response()->json(['success' => true, 'message' => 'Refund processed.', 'data' => $this->present($refund->fresh(['payment.order', 'payment.bill.order', 'requester', 'approver', 'processor']))]);
            });
        } catch (Throwable $e) { if ($proofPath) Storage::disk('public')->delete($proofPath); throw $e; }
    }

    private function present(RefundRequest $refund): RefundRequest
    {
        $refund->setAttribute('proof_url', $refund->proof_path ? Storage::disk('public')->url($refund->proof_path) : null);
        $refund->setAttribute('order', $refund->payment?->order ?? $refund->payment?->bill?->order);
        return $refund;
    }
}
