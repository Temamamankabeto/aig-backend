<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Order;
use App\Services\BillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    public function __construct(private BillService $billService)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Bill::class);
    
        $q = Bill::query()->with(['order.table', 'order.waiter', 'payments']);
    
        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
    
        if ($request->filled('order_id')) {
            $q->where('order_id', $request->integer('order_id'));
        }
    
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
    
            $q->whereHas('order', function ($sub) use ($search) {
                $sub->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }
    
        $bills = $q->latest('id')->paginate((int) $request->get('per_page', 20));
    
        return response()->json([
            'success' => true,
            'data' => $bills->items(),
            'meta' => [
                'current_page' => $bills->currentPage(),
                'last_page' => $bills->lastPage(),
                'per_page' => $bills->perPage(),
                'total' => $bills->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $bill = Bill::with(['order.table', 'order.waiter', 'order.items.menuItem', 'payments.receiver', 'payments.refundRequest'])->findOrFail($id);
        $this->authorize('view', $bill);
        return response()->json(['success' => true, 'data' => $bill]);
    }

    public function showByOrder($id)
    {
        $order = Order::with(['bill.payments.receiver', 'bill.payments.refundRequest', 'bill.order.items.menuItem'])->findOrFail($id);
        if ($order->bill) {
            $this->authorize('view', $order->bill);
        } else {
            $this->authorize('viewAny', Bill::class);
        }
        return response()->json(['success' => true, 'data' => $order->bill]);
    }

    public function createOrUpdateDraft($orderId)
    {
        $this->authorize('createOrUpdateDraft', Bill::class);
        return response()->json(['success' => true, 'data' => $this->billService->createOrUpdateDraft((int) $orderId)]);
    }

    public function issue(Request $request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $bill = Bill::lockForUpdate()->findOrFail($id);
            $this->authorize('issue', $bill);

            if (!in_array($bill->status, ['draft', 'issued', 'partial'], true)) {
                return response()->json(['success' => false, 'message' => 'Bill cannot be issued'], 422);
            }

            $bill->status = (float) $bill->paid_amount > 0 ? 'partial' : 'issued';
            $bill->issued_by = $request->user()->id;
            $bill->issued_at = $bill->issued_at ?: now();
            $bill->save();

            return response()->json(['success' => true, 'data' => $bill]);
        });
    }

    public function void($id)
    {
        return DB::transaction(function () use ($id) {
            $bill = Bill::with('payments')->lockForUpdate()->findOrFail($id);
            $this->authorize('void', $bill);

            if ((float) $bill->paid_amount > 0 || $bill->payments()->exists()) {
                return response()->json(['success' => false, 'message' => 'Cannot void a bill with payments'], 422);
            }

            $bill->update(['status' => 'void']);
            return response()->json(['success' => true, 'data' => $bill]);
        });
    }
}
