<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\StoreMenuCategoryRequest;
use App\Http\Requests\Authz\UpdateMenuCategoryRequest;
use App\Models\MenuCategory;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuCategory::class);

        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type');
        $active = $request->query('active');
        $perPage = max(5, min((int) $request->query('per_page', 10), 100));

        $q = MenuCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search !== '') {
            $q->where('name', 'like', "%{$search}%");
        }

        if (in_array($type, ['food', 'drink'], true)) {
            $q->where('type', $type);
        }

        if ($active === '1' || $active === '0') {
            $q->where('is_active', $active === '1');
        }

        $page = $q->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show($id)
    {
        $cat = MenuCategory::findOrFail($id);
        $this->authorize('view', $cat);

        return response()->json([
            'success' => true,
            'data' => $cat,
        ]);
    }

    public function store(StoreMenuCategoryRequest $request)
    {
        $this->authorize('create', MenuCategory::class);

        $data = $request->validated();

        $cat = MenuCategory::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created',
            'data' => $cat,
        ], 201);
    }

    public function update(UpdateMenuCategoryRequest $request, $id)
    {
        $cat = MenuCategory::findOrFail($id);
        $this->authorize('update', $cat);

        $data = $request->validated();

        $cat->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? $cat->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category updated',
            'data' => $cat->fresh(),
        ]);
    }

    public function toggle($id)
    {
        $cat = MenuCategory::findOrFail($id);
        $this->authorize('update', $cat);

        $cat->is_active = ! $cat->is_active;
        $cat->save();

        return response()->json([
            'success' => true,
            'message' => 'Category status updated successfully',
            'data' => $cat->fresh(),
        ]);
    }

    public function publicIndex(Request $request)
    {
        $type = $request->query('type');

        $q = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if (in_array($type, ['food', 'drink'], true)) {
            $q->where('type', $type);
        }

        return response()->json([
            'success' => true,
            'data' => $q->get(['id', 'name', 'type', 'icon', 'sort_order', 'is_active']),
        ]);
    }
}