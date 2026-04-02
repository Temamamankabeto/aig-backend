<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);
        $q = Supplier::query()->orderBy('id', 'desc');
        if ($request->filled('is_active')) $q->where('is_active', (bool) $request->is_active);
        return response()->json(['success' => true, 'data' => $q->paginate((int) ($request->get('per_page', 20)))]);
    }

    public function show($id)
    {
        $row = Supplier::with('purchaseOrders')->findOrFail($id);
        $this->authorize('view', $row);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Supplier::class);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'tax_id' => 'nullable|string|max:100',
            'credit_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        return response()->json(['success' => true, 'data' => Supplier::create($data)], 201);
    }

    public function update(Request $request, $id)
    {
        $row = Supplier::findOrFail($id);
        $this->authorize('update', $row);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'tax_id' => 'nullable|string|max:100',
            'credit_days' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $row->update($data);
        return response()->json(['success' => true, 'data' => $row]);
    }
}
