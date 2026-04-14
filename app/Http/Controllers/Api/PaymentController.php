<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\OrderSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogger $auditLogger,
        private OrderSettlementService $orderSettlementService,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payment::class);
    
        $q = Payment::query()
            ->with(['bill.order', 'receiver', 'refundRequest'])
            ->orderByDesc('id');
    
        if ($request->filled('bill_id')) {
            $q->where('bill_id', $request->integer('bill_id'));
        }
    
        if ($request->filled('status')) {
            $q->where('status', (string) $request->string('status'));
        }
    
        if ($request->filled('method')) {
            $q->where('method', (string) $request->string('method'));
        }
    
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
    
            $q->where(function ($sub) use ($search) {
                $sub->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('bill.order', function ($orderQ) use ($search) {
                        $orderQ->where('order_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%");
                    });
            });
        }
    
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $payments = $q->paginate($perPage);
    
        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $row = Payment::with(['bill.order.items.menuItem', 'receiver', 'refundRequest'])->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request, $billId)
    {
        $this->authorize('create', Payment::class);
    
        $data = $request->validate([
            'method' => 'required|in:cash,card,mobile,transfer',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'paid_at' => 'nullable|date',
            'cash_shift_id' => 'nullable|exists:cash_shifts,id',
            'screenshot_path' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);
    
        return DB::transaction(function () use ($request, $billId, $data) {
            $bill = Bill::lockForUpdate()
                ->with('order.table')
                ->findOrFail($billId);
    
            if ($bill->status === 'void') {
                return response()->json([
                    'success' => false,
                    'message' => 'Bill is void',
                ], 422);
            }
    
            $shiftId = $data['cash_shift_id'] ?? null;
    
            $shift = $shiftId
                ? CashShift::lockForUpdate()->findOrFail($shiftId)
                : CashShift::where('cashier_id', $request->user()->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
    
            if (! $shift || $shift->status !== 'open') {
                return response()->json([
                    'success' => false,
                    'message' => 'An open cash shift is required for all payments',
                ], 422);
            }
    
            if ((int) $shift->cashier_id !== (int) $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only record payments on your own open shift',
                ], 422);
            }
    
            $shiftId = $shift->id;
    
            $amount = round((float) $data['amount'], 2);
    
            if ($amount > round((float) $bill->balance, 2)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment exceeds remaining balance',
                ], 422);
            }
    
            $payment = Payment::create([
                'bill_id' => $bill->id,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'status' => 'paid',
                'received_by' => $request->user()->id,
                'cash_shift_id' => $shiftId,
                'paid_at' => $data['paid_at'] ?? now(),
                'screenshot_path' => $request->hasFile('screenshot_path')
                    ? $request->file('screenshot_path')->store('payments/screenshots', 'public')
                    : null,
            ]);
    
            $beforeBill = $bill->toArray();
    
            $bill->paid_amount = round((float) $bill->paid_amount + $amount, 2);
            $bill->balance = max(0, round((float) $bill->total - (float) $bill->paid_amount, 2));
    
            if ($bill->issued_at === null) {
                $bill->issued_by = $request->user()->id;
                $bill->issued_at = now();
            }
    
            $bill->status = (float) $bill->balance <= 0 ? 'paid' : 'partial';
    
            if ($bill->status === 'paid') {
                $bill->paid_at = now();
            }
    
            $bill->save();
    
            $this->orderSettlementService->settlePaidBill(
                $bill->fresh('order.table', 'payments'),
                $request,
                (int) $request->user()->id,
                'direct_payment'
            );
    
            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'Payment',
                $payment->id,
                'payment_recorded',
                null,
                $payment->fresh()->toArray(),
                'Payment recorded.'
            );
    
            $this->auditLogger->log(
                $request,
                $request->user()->id,
                'Bill',
                $bill->id,
                'bill_payment_applied',
                $beforeBill,
                $bill->fresh()->toArray(),
                'Payment applied to bill.'
            );
    
            return response()->json([
                'success' => true,
                'data' => [
                    'payment' => $payment->fresh(['receiver']),
                    'bill' => $bill->fresh(['payments', 'order']),
                ],
            ], 201);
        });
    }

    public function submitByWaiter(Request $request)
    {
        $this->authorize('submitByWaiter', Payment::class);
        $data = $request->validate([
            'bill_id' => 'required|exists:bills,id',
            'method' => 'required|in:cash,transfer',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
            'paid_at' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $bill = Bill::lockForUpdate()->with('order')->findOrFail($data['bill_id']);
            $order = Order::findOrFail($bill->order_id);
            if ($bill->status === 'void') return response()->json(['success' => false, 'message' => 'Bill is void'], 422);
            if (!in_array($order->status, ['served', 'delivered'], true)) return response()->json(['success' => false, 'message' => 'Only served or delivered orders can submit payment.'], 422);

            $amount = round((float) $data['amount'], 2);
            if ($amount > round((float) $bill->balance, 2)) return response()->json(['success' => false, 'message' => 'Submitted amount exceeds remaining balance'], 422);
            if ($data['method'] === 'transfer' && empty($data['reference'])) return response()->json(['success' => false, 'message' => 'Transfer reference is required'], 422);

            if (Payment::where('bill_id', $bill->id)->where('status', 'submitted')->lockForUpdate()->exists()) {
                return response()->json(['success' => false, 'message' => 'A payment for this bill is already submitted and waiting for approval.'], 422);
            }

            $receiptPath = $request->hasFile('receipt') ? $request->file('receipt')->store('payments/receipts', 'public') : null;

            $payment = Payment::create([
                'bill_id' => $bill->id,
                'method' => $data['method'],
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'receipt_path' => $receiptPath,
                'status' => 'submitted',
                'received_by' => $request->user()->id,
                'cash_shift_id' => null,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $this->notificationService->notifyUsersByPermission('payments.approve', 'Payment submitted', "A payment for order {$order->order_number} is waiting for approval.", ['kind' => 'payment_submitted', 'payment_id' => $payment->id, 'bill_id' => $bill->id, 'order_id' => $order->id]);
            $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_submitted', null, $payment->toArray(), 'Waiter submitted payment.');

            return response()->json(['success' => true, 'message' => 'Payment submitted for cashier approval', 'data' => ['payment' => $payment, 'bill' => $bill->fresh(['payments'])]], 201);
        });
    }

    public function pendingApproval(Request $request)
    {
        $this->authorize('pendingApproval', Payment::class);
        $q = Payment::query()->with(['bill.order.table', 'bill.order.waiter', 'bill.order.items.menuItem', 'receiver'])->where('status', 'submitted')->orderByDesc('id');
        if ($request->filled('method')) $q->where('method', (string) $request->string('method'));
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $q->where(function ($sub) use ($search) {
                $sub->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('bill', function ($billQ) use ($search) {
                        $billQ->where('bill_number', 'like', "%{$search}%")
                            ->orWhereHas('order', function ($orderQ) use ($search) {
                                $orderQ->where('order_number', 'like', "%{$search}%")
                                    ->orWhere('customer_name', 'like', "%{$search}%")
                                    ->orWhere('customer_phone', 'like', "%{$search}%");
                            });
                    });
            });
        }
        $rows = $q->paginate((int) $request->get('per_page', 20));
        $rows->getCollection()->transform(function ($payment) { if ($payment->receipt_path) $payment->receipt_url = url('storage/' . $payment->receipt_path); return $payment; });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function approve(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $payment = Payment::with(['bill.order.table'])->lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $payment);
            if ($payment->status !== 'submitted') return response()->json(['success' => false, 'message' => 'Only submitted payments can be approved'], 422);

            $bill = Bill::lockForUpdate()->findOrFail($payment->bill_id);
            $beforeBill = $bill->toArray();
            $beforePayment = $payment->toArray();
            $amount = round((float) $payment->amount, 2);
            $newPaidAmount = round((float) $bill->paid_amount + $amount, 2);
            $newBalance = max(0, round((float) $bill->total - $newPaidAmount, 2));

            $shiftId = null;
            if ($payment->method === 'cash') {
                $shift = CashShift::where('cashier_id', $request->user()->id)->where('status', 'open')->lockForUpdate()->first();
                if (!$shift || $shift->status !== 'open') return response()->json(['success' => false, 'message' => 'An open cash shift is required to approve cash payments'], 422);
                $shiftId = $shift->id;
            }

            $payment->update(['status' => 'paid', 'cash_shift_id' => $shiftId]);
            if ($bill->issued_at === null) {
                $bill->issued_by = $request->user()->id;
                $bill->issued_at = now();
            }
            $bill->paid_amount = $newPaidAmount;
            $bill->balance = $newBalance;
            $bill->status = $newBalance <= 0 ? 'paid' : 'partial';
            if ($bill->status === 'paid') $bill->paid_at = now();
            $bill->save();

            $this->orderSettlementService->settlePaidBill($bill->fresh('order.table', 'payments'), $request, (int) $request->user()->id, 'approved_payment');
            $order = $bill->fresh('order')->order;
            $this->notificationService->notifyUser($order?->waiter_id, 'Payment approved', "Payment for order {$order?->order_number} was approved.", ['kind' => 'payment_approved', 'payment_id' => $payment->id, 'bill_id' => $bill->id, 'order_id' => $order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_approved', $beforePayment, $payment->fresh()->toArray(), 'Submitted payment approved.');
            $this->auditLogger->log($request, $request->user()->id, 'Bill', $bill->id, 'bill_payment_applied', $beforeBill, $bill->fresh()->toArray(), 'Approved payment applied to bill.');

            return response()->json(['success' => true, 'message' => 'Payment approved successfully', 'data' => ['payment' => $payment->fresh(['receiver']), 'bill' => $bill->fresh(['payments', 'order'])]]);
        });
    }

    public function returnPayment(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $payment = Payment::with('bill.order')->lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $payment);
            if ($payment->status !== 'submitted') return response()->json(['success' => false, 'message' => 'Only submitted payments can be returned'], 422);
            $before = $payment->toArray();
            $payment->update(['status' => 'returned']);
            $order = $payment->bill?->order;
            $this->notificationService->notifyUser($order?->waiter_id, 'Payment returned', "Payment for order {$order?->order_number} was returned.", ['kind' => 'payment_returned', 'payment_id' => $payment->id, 'bill_id' => $payment->bill_id, 'order_id' => $order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_returned', $before, $payment->fresh()->toArray(), 'Payment returned.');
            return response()->json(['success' => true, 'message' => 'Payment returned successfully', 'data' => $payment]);
        });
    }

    public function fail(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $payment = Payment::with('bill.order')->lockForUpdate()->findOrFail($id);
            $this->authorize('approve', $payment);
            if ($payment->status !== 'submitted') return response()->json(['success' => false, 'message' => 'Only submitted payments can be marked failed'], 422);
            $before = $payment->toArray();
            $payment->update(['status' => 'failed']);
            $order = $payment->bill?->order;
            $this->notificationService->notifyUser($order?->waiter_id, 'Payment failed', "Payment for order {$order?->order_number} was marked failed.", ['kind' => 'payment_failed', 'payment_id' => $payment->id, 'bill_id' => $payment->bill_id, 'order_id' => $order?->id]);
            $this->auditLogger->log($request, $request->user()->id, 'Payment', $payment->id, 'payment_failed', $before, $payment->fresh()->toArray(), 'Payment failed.');
            return response()->json(['success' => true, 'message' => 'Payment marked as failed', 'data' => $payment]);
        });
    }

    public function waiterReport(Request $request)
    {
        $this->authorize('waiterReport', Payment::class);
        $user = $request->user();
        $q = Payment::query()->with(['bill.order.table', 'bill.order.items.menuItem', 'cashShift'])->where('received_by', $user->id)->whereIn('method', ['cash', 'transfer'])->orderByDesc('id');
        if ($request->filled('method') && in_array($request->method, ['cash', 'transfer'])) $q->where('method', $request->method);
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('date_from')) $q->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $q->whereDate('created_at', '<=', $request->date_to);
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $q->where(function ($sub) use ($search) {
                $sub->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('bill', function ($billQ) use ($search) {
                        $billQ->where('bill_number', 'like', "%{$search}%")
                            ->orWhereHas('order', function ($orderQ) use ($search) {
                                $orderQ->where('order_number', 'like', "%{$search}%")
                                    ->orWhere('customer_name', 'like', "%{$search}%")
                                    ->orWhere('customer_phone', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $rows = $q->paginate((int) $request->get('per_page', 15));
        $rows->getCollection()->transform(function ($payment) {
            $payment->receipt_url = $payment->receipt_path ? url('storage/' . $payment->receipt_path) : null;
            return $payment;
        });

        $baseSummary = Payment::query()->where('received_by', $user->id)->whereIn('method', ['cash', 'transfer']);
        if ($request->filled('date_from')) $baseSummary->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $baseSummary->whereDate('created_at', '<=', $request->date_to);
        if ($request->filled('method') && in_array($request->method, ['cash', 'transfer'])) $baseSummary->where('method', $request->method);
        if ($request->filled('status')) $baseSummary->where('status', $request->status);

        $summary = [
            'payments_count' => (clone $baseSummary)->count(),
            'total_amount' => round((float) (clone $baseSummary)->sum('amount'), 2),
            'submitted_amount' => round((float) (clone $baseSummary)->where('status', 'submitted')->sum('amount'), 2),
            'paid_amount' => round((float) (clone $baseSummary)->where('status', 'paid')->sum('amount'), 2),
            'returned_amount' => round((float) (clone $baseSummary)->where('status', 'returned')->sum('amount'), 2),
            'failed_amount' => round((float) (clone $baseSummary)->where('status', 'failed')->sum('amount'), 2),
        ];

        return response()->json(['success' => true, 'data' => ['rows' => $rows, 'summary' => $summary]]);
    }
}
