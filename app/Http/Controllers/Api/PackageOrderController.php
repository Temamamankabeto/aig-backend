<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Package;
use App\Models\PackageOrder;
use App\Models\PackageOrderPayment;
use App\Models\PackageOrderSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageOrderController extends Controller
{
    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'You are not authorized to perform this package order action.');
    }

    public function packages(Request $request)
    {
        $this->requirePermission($request, 'packages.read');
        $q = Package::with('items.menuItem')->latest();
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(fn($x) => $x->where('name', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"));
        }
        if ($request->filled('active')) $q->where('is_active', (bool) $request->boolean('active'));
        $rows = $q->paginate(max(1, min((int) $request->query('per_page', 10), 100)));
        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => $this->meta($rows)]);
    }

    public function storePackage(Request $request)
    {
        // Admin responsibility: package templates, package items and pricing setup.
        $this->requirePermission($request, 'packages.create');
        $data = $this->validatePackage($request);
        $package = DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $package = Package::create($data);
            foreach ($items as $item) {
                $package->items()->create($item);
            }
            return $package->fresh('items.menuItem');
        });
        return response()->json(['success' => true, 'message' => 'Package created successfully.', 'data' => $package], 201);
    }

    public function showPackage(Request $request, $id)
    {
        $this->requirePermission($request, 'packages.read');
        return response()->json(['success' => true, 'data' => Package::with('items.menuItem')->findOrFail($id)]);
    }

    public function updatePackage(Request $request, $id)
    {
        // Admin responsibility: maintain package template and pricing rules.
        $this->requirePermission($request, 'packages.update');
        $package = Package::findOrFail($id);
        $data = $this->validatePackage($request, true);
        $package = DB::transaction(function () use ($package, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            $package->update($data);
            if (is_array($items)) {
                $package->items()->delete();
                foreach ($items as $item) {
                    $package->items()->create($item);
                }
            }
            return $package->fresh('items.menuItem');
        });
        return response()->json(['success' => true, 'message' => 'Package updated successfully.', 'data' => $package]);
    }

    public function deletePackage(Request $request, $id)
    {
        $this->requirePermission($request, 'packages.delete');
        Package::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Package deleted successfully.']);
    }

    public function orders(Request $request)
    {
        $this->requirePermission($request, 'package.orders.read');
        $q = PackageOrder::with(['package', 'items.menuItem', 'schedule', 'creator'])->latest();
        if ($request->filled('status')) $q->where('status', $request->status);
        if ($request->filled('payment_type')) $q->where('payment_type', $request->payment_type);
        if ($request->filled('event_date')) $q->whereDate('event_date', $request->event_date);
        if ($request->filled('service_style')) $q->where('service_style', $request->service_style);
        if ($request->filled('venue_section')) $q->where('venue_section', 'like', '%' . trim((string) $request->venue_section) . '%');
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $q->where(fn($x) => $x->where('package_order_number', 'like', "%{$s}%")
                ->orWhere('event_name', 'like', "%{$s}%")
                ->orWhere('delivery_location', 'like', "%{$s}%")
                ->orWhere('venue_section', 'like', "%{$s}%"));
        }
        $rows = $q->paginate(max(1, min((int) $request->query('per_page', 10), 100)));
        return response()->json(['success' => true, 'data' => $rows->items(), 'meta' => $this->meta($rows)]);
    }

    public function storeOrder(Request $request)
    {
        // Manager responsibility: create event booking and capture event details.
        $this->requirePermission($request, 'package.orders.create');
        $data = $request->validate([
            'package_id' => 'nullable|exists:packages,id',
            'customer_id' => 'nullable|integer',
            'organization_id' => 'nullable|integer',
            'event_name' => 'required|string|max:255',
            'event_type' => 'nullable|string|max:100',
            'guest_count' => 'required|integer|min:1',
            'actual_guests' => 'nullable|integer|min:0',
            'event_date' => 'required|date',
            'event_time' => 'nullable|date_format:H:i',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'venue_section' => 'nullable|string|max:255',
            'service_style' => 'nullable|in:plated,buffet,family_style,food_stations,takeaway,delivery',
            'delivery_location' => 'nullable|string|max:255',
            'allergen_notes' => 'nullable|string|max:2000',
            'vegetarian_count' => 'nullable|integer|min:0',
            'special_notes' => 'nullable|string|max:2000',
            'payment_type' => 'required|in:cash,card,mobile,transfer,credit',
            'discount' => 'nullable|numeric|min:0',
            'deposit_required' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'items' => 'nullable|array|min:1',
            'items.*.menu_item_id' => 'required_with:items|exists:menu_items,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $order = DB::transaction(function () use ($data, $request) {
            $items = $this->buildOrderItems($data);
            $totals = $this->calculateTotals($items, (float) ($data['discount'] ?? 0));
            $order = PackageOrder::create([
                'package_id' => $data['package_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'organization_id' => $data['organization_id'] ?? null,
                'package_order_number' => $this->number(),
                'event_name' => $data['event_name'],
                'event_type' => $data['event_type'] ?? null,
                'guest_count' => $data['guest_count'],
                'actual_guests' => $data['actual_guests'] ?? null,
                'event_date' => $data['event_date'],
                'event_time' => $data['event_time'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'venue_section' => $data['venue_section'] ?? null,
                'service_style' => $data['service_style'] ?? null,
                'delivery_location' => $data['delivery_location'] ?? null,
                'allergen_notes' => $data['allergen_notes'] ?? null,
                'vegetarian_count' => $data['vegetarian_count'] ?? 0,
                'special_notes' => $data['special_notes'] ?? null,
                'service_status' => 'not_started',
                'status' => 'draft',
                'payment_type' => $data['payment_type'],
                'credit_status' => $data['payment_type'] === 'credit' ? 'credit_pending' : null,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'service_charge' => $totals['service_charge'],
                'discount' => $totals['discount'],
                'total' => $totals['total'],
                'paid_amount' => 0,
                'balance' => $totals['total'],
                'deposit_required' => $data['deposit_required'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            foreach ($items as $item) {
                $order->items()->create($item);
            }
            return $order->fresh(['package','items.menuItem','schedule','payments']);
        });

        return response()->json(['success' => true, 'message' => 'Event package booking created successfully.', 'data' => $order], 201);
    }

    public function showOrder(Request $request, $id)
    {
        $this->requirePermission($request, 'package.orders.read');
        return response()->json(['success' => true, 'data' => PackageOrder::with(['package.items.menuItem','items.menuItem','schedule','payments.receiver','creator','approver'])->findOrFail($id)]);
    }

    public function updateOrder(Request $request, $id)
    {
        // Manager responsibility: update booking details before operational lock.
        $this->requirePermission($request, 'package.orders.update');
        $order = PackageOrder::findOrFail($id);
        abort_unless(in_array($order->status, ['draft','quoted'], true), 422, 'Only draft or quoted package orders can be edited.');
        $data = $request->validate([
            'event_name' => 'sometimes|string|max:255', 'event_type' => 'nullable|string|max:100', 'guest_count' => 'sometimes|integer|min:1',
            'actual_guests' => 'nullable|integer|min:0', 'event_date' => 'sometimes|date', 'event_time' => 'nullable|date_format:H:i',
            'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i',
            'venue_section' => 'nullable|string|max:255', 'service_style' => 'nullable|in:plated,buffet,family_style,food_stations,takeaway,delivery',
            'delivery_location' => 'nullable|string|max:255', 'allergen_notes' => 'nullable|string|max:2000',
            'vegetarian_count' => 'nullable|integer|min:0', 'special_notes' => 'nullable|string|max:2000',
            'payment_type' => 'sometimes|in:cash,card,mobile,transfer,credit', 'discount' => 'nullable|numeric|min:0',
            'deposit_required' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:2000',
        ]);
        $order->update($data);
        return response()->json(['success' => true, 'message' => 'Event package booking updated successfully.', 'data' => $order->fresh(['items.menuItem','schedule','payments'])]);
    }

    public function quote(Request $request, $id) { return $this->transition($request, $id, 'package.orders.update', ['draft'], 'quoted', 'Event package order quoted.'); }
    public function approve(Request $request, $id) { return $this->transition($request, $id, 'package.orders.approve', ['draft','quoted'], 'approved', 'Event package order approved.', true); }
    public function startPreparation(Request $request, $id) { return $this->transition($request, $id, 'package.orders.prepare', ['approved','scheduled'], 'preparing', 'Event package preparation started.'); }
    public function markReady(Request $request, $id) { return $this->transition($request, $id, 'package.orders.prepare', ['preparing'], 'ready', 'Event package items marked ready.'); }
    public function deliver(Request $request, $id) { return $this->transition($request, $id, 'package.orders.deliver', ['ready'], 'delivered', 'Event package delivered.'); }
    public function complete(Request $request, $id) { return $this->transition($request, $id, 'package.orders.complete', ['delivered'], 'completed', 'Event package order completed.', false, true); }
    public function cancel(Request $request, $id) { return $this->transition($request, $id, 'package.orders.cancel', ['draft','quoted','approved','scheduled'], 'cancelled', 'Event package order cancelled.'); }

    public function schedule(Request $request, $id)
    {
        // Kitchen Manager responsibility: prep timeline and assigned team/station.
        $this->requirePermission($request, 'package.orders.schedule');
        $order = PackageOrder::findOrFail($id);
        abort_unless(in_array($order->status, ['approved','scheduled'], true), 422, 'Only approved package orders can be scheduled.');
        $data = $request->validate([
            'prep_start_time' => 'nullable|date', 'ready_time' => 'nullable|date', 'delivery_time' => 'nullable|date',
            'assigned_team' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
        ]);
        $data['status'] = 'scheduled';
        PackageOrderSchedule::updateOrCreate(['package_order_id' => $order->id], $data);
        $order->update(['status' => 'scheduled']);
        return response()->json(['success' => true, 'message' => 'Event package order scheduled successfully.', 'data' => $order->fresh(['items.menuItem','schedule'])]);
    }

    public function updateService(Request $request, $id)
    {
        // Manager responsibility: supervise event execution, progress, actual guests, delays and service completion.
        $this->requirePermission($request, 'package.orders.service');
        $order = PackageOrder::findOrFail($id);
        abort_unless(in_array($order->status, ['approved','scheduled','preparing','ready','delivered'], true), 422, 'Service progress can only be updated for approved or active event package orders.');
        $data = $request->validate([
            'service_status' => 'required|in:not_started,in_progress,delayed,completed',
            'actual_guests' => 'nullable|integer|min:0',
            'service_progress_note' => 'nullable|string|max:2000',
            'delay_reason' => 'nullable|string|max:2000',
        ]);
        if (($data['service_status'] ?? null) === 'completed' && $order->status === 'delivered') {
            $data['completed_at'] = now();
            $data['status'] = 'completed';
        }
        $order->update($data);
        return response()->json(['success' => true, 'message' => 'Event service progress updated successfully.', 'data' => $order->fresh(['items.menuItem','schedule','payments'])]);
    }

    public function payment(Request $request, $id)
    {
        // Finance responsibility: collect deposits, record payments and settle credit/final balance.
        $this->requirePermission($request, 'package.orders.settle');
        $order = PackageOrder::lockForUpdate()->findOrFail($id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|in:cash,card,mobile,transfer',
            'reference_number' => 'nullable|string|max:255', 'paid_at' => 'nullable|date', 'notes' => 'nullable|string|max:1000',
        ]);
        $order = DB::transaction(function () use ($order, $data, $request) {
            $locked = PackageOrder::lockForUpdate()->findOrFail($order->id);
            $amount = round((float) $data['amount'], 2);
            abort_if($amount > (float) $locked->balance, 422, 'Payment amount exceeds remaining balance.');
            PackageOrderPayment::create([
                'package_order_id' => $locked->id, 'amount' => $amount, 'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null, 'received_by' => $request->user()->id,
                'paid_at' => $data['paid_at'] ?? now(), 'notes' => $data['notes'] ?? null,
            ]);
            $locked->paid_amount = round((float) $locked->paid_amount + $amount, 2);
            $locked->balance = max(0, round((float) $locked->total - (float) $locked->paid_amount, 2));
            if ($locked->payment_type === 'credit') $locked->credit_status = $locked->balance <= 0 ? 'fully_settled' : 'partially_settled';
            $locked->save();
            return $locked->fresh(['items.menuItem','schedule','payments.receiver']);
        });
        return response()->json(['success' => true, 'message' => 'Package event payment recorded successfully.', 'data' => $order], 201);
    }

    public function reportSummary(Request $request)
    {
        $this->requirePermission($request, 'package.orders.read');
        return response()->json(['success' => true, 'data' => [
            'total_package_orders' => PackageOrder::count(),
            'approved' => PackageOrder::where('status', 'approved')->count(),
            'scheduled' => PackageOrder::where('status', 'scheduled')->count(),
            'preparing' => PackageOrder::where('status', 'preparing')->count(),
            'delivered' => PackageOrder::where('status', 'delivered')->count(),
            'service_in_progress' => PackageOrder::where('service_status', 'in_progress')->count(),
            'service_delayed' => PackageOrder::where('service_status', 'delayed')->count(),
            'outstanding_balance' => round((float) PackageOrder::sum('balance'), 2),
            'today_events' => PackageOrder::whereDate('event_date', today())->count(),
        ]]);
    }

    private function validatePackage(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'price_per_person' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'minimum_people' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'is_active' => 'sometimes|boolean',
            'items' => 'sometimes|array',
            'items.*.menu_item_id' => 'required_with:items|exists:menu_items,id',
            'items.*.quantity_per_person' => 'required_with:items|numeric|min:0.001',
        ]);
    }

    private function buildOrderItems(array $data): array
    {
        if (!empty($data['items'])) {
            return collect($data['items'])->map(function ($item) {
                $menu = MenuItem::findOrFail($item['menu_item_id']);
                $qty = round((float) $item['quantity'], 3);
                $price = round((float) ($item['unit_price'] ?? $menu->price), 2);
                return ['menu_item_id' => $menu->id, 'quantity' => $qty, 'unit_price' => $price, 'total_price' => round($qty * $price, 2)];
            })->all();
        }
        $package = Package::with('items.menuItem')->findOrFail($data['package_id'] ?? 0);
        return $package->items->map(function ($item) use ($data) {
            $qty = round((float) $item->quantity_per_person * (int) $data['guest_count'], 3);
            $price = round((float) $item->menuItem->price, 2);
            return ['menu_item_id' => $item->menu_item_id, 'quantity' => $qty, 'unit_price' => $price, 'total_price' => round($qty * $price, 2)];
        })->all();
    }

    private function calculateTotals(array $items, float $discount): array
    {
        $subtotal = round(array_sum(array_column($items, 'total_price')), 2);
        $tax = round(max(0, $subtotal - $discount) * 0.10, 2);
        $service = round(max(0, $subtotal - $discount) * 0.05, 2);
        $total = max(0, round($subtotal - $discount + $tax + $service, 2));
        return ['subtotal' => $subtotal, 'discount' => round($discount, 2), 'tax' => $tax, 'service_charge' => $service, 'total' => $total];
    }

    private function transition(Request $request, $id, string $permission, array $allowed, string $next, string $message, bool $approve = false, bool $complete = false)
    {
        $this->requirePermission($request, $permission);
        $order = PackageOrder::findOrFail($id);
        abort_unless(in_array($order->status, $allowed, true), 422, 'Invalid package order status transition.');
        $payload = ['status' => $next];
        if ($approve) { $payload['approved_by'] = $request->user()->id; $payload['approved_at'] = now(); if ($order->payment_type === 'credit') $payload['credit_status'] = 'credit_approved'; }
        if ($complete) { $payload['completed_at'] = now(); $payload['service_status'] = 'completed'; }
        $order->update($payload);
        return response()->json(['success' => true, 'message' => $message, 'data' => $order->fresh(['items.menuItem','schedule','payments'])]);
    }

    private function number(): string
    {
        do { $number = 'PKG-' . now()->format('Ymd') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT); }
        while (PackageOrder::where('package_order_number', $number)->exists());
        return $number;
    }

    private function meta($rows): array
    {
        return ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()];
    }
}
