<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuItemController extends Controller
{
    public function publicmenu(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type');
        $categoryId = $request->query('category_id');
        $menuMode = $request->query('menu_mode');

        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $page = (int) $request->query('page', 1);
        $page = max(1, $page);

        $query = MenuItem::query()
            ->with(['category:id,name,type'])
            ->where('is_active', true)
            ->where('is_available', true)
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($type && in_array($type, ['food', 'drink'], true)) {
            $query->where('type', $type);
        }

        if ($categoryId && is_numeric($categoryId)) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($menuMode && in_array($menuMode, ['normal', 'spatial'], true)) {
            $query->where('menu_mode', $menuMode);
        }

        $paginatedItems = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginatedItems->getCollection()
            ->map(fn ($item) => $this->transformItem($item, true))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginatedItems->currentPage(),
                'per_page' => $paginatedItems->perPage(),
                'total' => $paginatedItems->total(),
                'last_page' => $paginatedItems->lastPage(),
                'filters' => [
                    'search' => $search,
                    'type' => $type,
                    'category_id' => $categoryId,
                    'menu_mode' => $menuMode,
                ],
            ],
        ]);
    }

    public function publicCategories()
    {
        $categories = MenuCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'icon', 'sort_order']);

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function publicIndex(Request $request)
    {
        $q = MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('category:id,name')
            ->orderBy('name');

        if ($request->filled('type') && in_array($request->query('type'), ['food', 'drink'], true)) {
            $q->where('type', $request->query('type'));
        }

        if ($request->filled('category_id') && is_numeric($request->query('category_id'))) {
            $q->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('menu_mode') && in_array($request->query('menu_mode'), ['normal', 'spatial'], true)) {
            $q->where('menu_mode', $request->query('menu_mode'));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));

            if ($term !== '') {
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            }
        }

        $items = $q->get()
            ->map(fn ($it) => $this->transformItem($it, false))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'count' => $items->count(),
                'filters' => [
                    'type' => $request->query('type'),
                    'category_id' => $request->query('category_id'),
                    'menu_mode' => $request->query('menu_mode'),
                    'q' => $request->query('q'),
                ],
            ],
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);

        $q = MenuItem::query()->with('category');

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));

            if ($term !== '') {
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            }
        }

        if ($request->filled('type') && in_array($request->query('type'), ['food', 'drink'], true)) {
            $q->where('type', $request->query('type'));
        }

        if ($request->filled('category_id') && is_numeric($request->query('category_id'))) {
            $q->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('menu_mode') && in_array($request->query('menu_mode'), ['normal', 'spatial'], true)) {
            $q->where('menu_mode', $request->query('menu_mode'));
        }

        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('is_available')) {
            $q->where('is_available', $request->boolean('is_available'));
        }

        if ($request->filled('is_featured')) {
            $q->where('is_featured', $request->boolean('is_featured'));
        }

        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(5, min($perPage, 100));

        $page = $q->orderByDesc('id')->paginate($perPage);

        $items = collect($page->items())
            ->map(fn ($item) => $this->transformItem($item, true))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $items,
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
        $item = MenuItem::with('category')->findOrFail($id);
        $this->authorize('view', $item);

        return response()->json([
            'success' => true,
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function showPublic($id)
    {
        $item = MenuItem::with('category')
            ->where('is_active', true)
            ->where('is_available', true)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function store(StoreMenuItemRequest $request)
    {
        $this->authorize('create', MenuItem::class);

        $data = $this->normalizePayload($request->validated());

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        }

        $item = MenuItem::create($data);
        $item->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Menu item created successfully.',
            'data' => $this->transformItem($item, true),
        ], 201);
    }

    public function update(UpdateMenuItemRequest $request, $id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('update', $item);

        $data = $this->normalizePayload($request->validated());

        if (array_key_exists('remove_image', $data) && $data['remove_image'] === true) {
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
            }

            $data['image_path'] = null;
        }

        unset($data['remove_image']);

        if ($request->hasFile('image')) {
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
            }

            $data['image_path'] = $this->storeImage($request->file('image'), $item->id);
        }

        $item->fill($data);
        $item->save();
        $item->load('category');

        return response()->json([
            'success' => true,
            'message' => 'Menu item updated successfully.',
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function toggleActive($id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('toggleActive', $item);

        $item->is_active = !$item->is_active;
        $item->save();

        return response()->json([
            'success' => true,
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function setAvailability(Request $request, $id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('setAvailability', $item);

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $item->is_available = (bool) $validated['is_available'];
        $item->save();

        return response()->json([
            'success' => true,
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function setSpatial($id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('update', $item);

        $item->menu_mode = 'spatial';
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Menu item set to spatial successfully.',
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function setNormal($id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('update', $item);

        $item->menu_mode = 'normal';
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Menu item set to normal successfully.',
            'data' => $this->transformItem($item, true),
        ]);
    }

    public function uploadImage(Request $request, $id)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
        ]);

        $item = MenuItem::findOrFail($id);
        $this->authorize('update', $item);

        if ($item->image_path) {
            Storage::disk('public')->delete($item->image_path);
        }

        $item->image_path = $this->storeImage($request->file('image'), $item->id);
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => $this->transformItem($item, true),
        ]);
    }

    protected function normalizePayload(array $data): array
    {
        foreach (['is_available', 'is_active', 'is_featured', 'remove_image'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }

        if (array_key_exists('price', $data) && $data['price'] !== null && $data['price'] !== '') {
            $data['price'] = (float) $data['price'];
        }

        if (array_key_exists('prep_minutes', $data)) {
            $data['prep_minutes'] = ($data['prep_minutes'] === null || $data['prep_minutes'] === '')
                ? null
                : (int) $data['prep_minutes'];
        }

        if (array_key_exists('modifiers', $data) && is_string($data['modifiers'])) {
            $decoded = json_decode($data['modifiers'], true);
            $data['modifiers'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return $data;
    }

    protected function storeImage($image, ?int $itemId = null): string
    {
        $extension = strtolower($image->getClientOriginalExtension());

        $imageName = $itemId
            ? 'menu_' . $itemId . '_' . time() . '_' . Str::random(10) . '.' . $extension
            : 'menu_' . time() . '_' . Str::random(10) . '.' . $extension;

        return $image->storeAs('menu-items', $imageName, 'public');
    }

    protected function transformItem(MenuItem $item, bool $withCategoryObject = true): array
    {
        $data = [
            'id' => $item->id,
            'category_id' => $item->category_id,
            'name' => $item->name,
            'description' => $item->description,
            'type' => $item->type,
            'price' => (float) $item->price,
            'image_path' => $item->image_path,
            'image_url' => $item->image_path ? asset('storage/' . $item->image_path) : null,
            'is_available' => (bool) $item->is_available,
            'is_active' => (bool) $item->is_active,
            'is_featured' => (bool) ($item->is_featured ?? false),
            'menu_mode' => $item->menu_mode,
            'has_ingredients' => (bool) ($item->has_ingredients ?? true),
            'prep_minutes' => $item->prep_minutes,
            'inventory_tracking_mode' => $item->inventory_tracking_mode,
            'direct_inventory_item_id' => $item->direct_inventory_item_id,
            'modifiers' => $item->modifiers,
            'views_count' => (int) ($item->views_count ?? 0),
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];

        if ($withCategoryObject) {
            $data['category'] = $item->category ? [
                'id' => $item->category->id,
                'name' => $item->category->name,
                'type' => $item->category->type ?? null,
            ] : null;
        } else {
            $data['category'] = $item->category?->name;
        }

        return $data;
    }
}