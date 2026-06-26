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
        $active = $request->query('active');
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
    
        $query = MenuCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name');
    
        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
    
    
        if ($active === '1' || $active === '0') {
            $query->where('is_active', $active === '1');
        }
    
        $categories = $query->paginate($perPage);
    
        return response()->json([
            'success' => true,
            'message' => 'Menu categories retrieved successfully',
            'data' => collect($categories->items())->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'sort_order' => $category->sort_order,
                    'is_active' => (bool) $category->is_active,
                    'status' => $category->is_active ? 'active' : 'inactive',
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            }),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }

    public function store(StoreMenuCategoryRequest $request)
    {
        $this->authorize('create', MenuCategory::class);

        $data = $request->validated();

        $cat = MenuCategory::create([
            'name' => $data['name'],
            // Legacy column kept only for DB compatibility. Category is organizational only.
            'type' => 'food',
            'description' => $data['description'] ?? null,
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
            'description' => $data['description'] ?? null,
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

    public function destroy($id)
    {
        $cat = MenuCategory::findOrFail($id);
        $this->authorize('delete', $cat);

        $cat->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    public function publicIndex(Request $request)
    {

        $q = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');


        return response()->json([
            'success' => true,
            'data' => $q->get(['id', 'name', 'description', 'icon', 'sort_order', 'is_active']),
        ]);
    }
}