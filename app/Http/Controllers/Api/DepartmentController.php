<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function index(Request $request)
    {
        $rows = Department::query()
            ->when($request->boolean('with_trashed'), fn ($query) => $query->withTrashed())
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';
                $query->where(fn ($search) => $search->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min(max((int) $request->input('per_page', 50), 1), 200));

        return response()->json(['success' => true, 'message' => 'Departments retrieved.', 'data' => $rows]);
    }

    public function show(Department $department)
    {
        return response()->json(['success' => true, 'message' => 'Department retrieved.', 'data' => $department]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('departments', 'code')],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] = $data['is_active'] ?? true;
        $department = Department::create($data);
        $this->auditLogger->log($request, $request->user()->id, 'Department', $department->id, 'department_created', null, $department->toArray(), 'Department created.');

        return response()->json(['success' => true, 'message' => 'Department created successfully.', 'data' => $department], 201);
    }

    public function update(Request $request, Department $department)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('departments', 'code')->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $department->toArray();
        $department->update($data);
        $this->auditLogger->log($request, $request->user()->id, 'Department', $department->id, 'department_updated', $before, $department->fresh()->toArray(), 'Department updated.');

        return response()->json(['success' => true, 'message' => 'Department updated successfully.', 'data' => $department->fresh()]);
    }

    public function destroy(Request $request, Department $department)
    {
        $before = $department->toArray();
        $department->delete();
        $this->auditLogger->log($request, $request->user()->id, 'Department', $department->id, 'department_deleted', $before, null, 'Department deleted.');

        return response()->json(['success' => true, 'message' => 'Department deleted successfully.', 'data' => null]);
    }
}
