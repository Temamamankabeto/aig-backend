<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $rows = AuditLog::query()
            ->with('user')
            ->when($request->filled('entity_type'), fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('entity_id'), fn($q) => $q->where('entity_id', (int) $request->entity_id))
            ->when($request->filled('action'), fn($q) => $q->where('action', $request->action))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', (int) $request->user_id))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 30));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(int $id)
    {
        $row = AuditLog::with('user')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $row]);
    }
}
