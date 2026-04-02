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

if ($type && in_array($type, ['food', 'drink'])) {
$query->where('type', $type);
}

if ($categoryId && is_numeric($categoryId)) {
$query->where('category_id', (int) $categoryId);
}

$items = $query->get()->map(function ($item) {
return [
'id' => $item->id,
'category_id' => $item->category_id,
'name' => $item->name,
'description' => $item->description,
'type' => $item->type,
'price' => (float) $item->price,
'image_path' => $item->image_path,
'image_url' => $item->image_path ? url('storage/' . $item->image_path) : null,
'is_available' => (bool) $item->is_available,
'is_active' => (bool) $item->is_active,
'prep_minutes' => $item->prep_minutes,
'modifiers' => $item->modifiers,
'category' => $item->category ? [
'id' => $item->category->id,
'name' => $item->category->name,
'type' => $item->category->type,
] : null,
];
});

return response()->json([
'success' => true,
'data' => $items,
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

    // Public items (active + available)
    public function publicIndex(Request $request)
    {
        $q = MenuItem::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('category:id,name')
            ->orderBy('name');

        if ($request->filled('type')) {
            $q->where('type', $request->query('type'));
        }

        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->query('category_id'));
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

        $items = $q->get()->map(function ($it) {
            return [
                'id' => $it->id,
                'category_id' => $it->category_id,
                'category' => $it->category?->name,
                'name' => $it->name,
                'description' => $it->description,
                'type' => $it->type,
                'price' => (float) $it->price,
                'prep_minutes' => $it->prep_minutes,
                'image_path' => $it->image_path,
                'image_url' => $it->image_path ? url('storage/' . $it->image_path) : null,
                'modifiers' => $it->modifiers,
                'is_available' => $it->is_available,
                'is_active' => $it->is_active,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'count' => $items->count(),
                'filters' => [
                    'type' => $request->query('type'),
                    'category_id' => $request->query('category_id'),
                    'q' => $request->query('q'),
                ],
            ],
        ]);
    }

    // Admin items (all)
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

   if ($request->filled('type')) {
   $q->where('type', $request->type);
   }

   if ($request->filled('category_id')) {
   $q->where('category_id', $request->category_id);
   }

   if ($request->filled('is_active')) {
   $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
   }

   if ($request->filled('is_available')) {
   $q->where('is_available', filter_var($request->is_available, FILTER_VALIDATE_BOOLEAN));
   }

   $perPage = (int) $request->query('per_page', 10);
   $perPage = max(5, min($perPage, 100));

   $page = $q->orderBy('id', 'desc')->paginate($perPage);

   $items = collect($page->items())->map(function ($item) {
   $data = $item->toArray();
   $data['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;
   return $data;
   });

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
        $data = $item->toArray();
        $data['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function showPublic($id)
    {
        $item = MenuItem::with('category')
            ->where('is_active', true)
            ->where('is_available', true)
            ->findOrFail($id);

        $data = $item->toArray();
        $data['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(StoreMenuItemRequest $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $request->validated();
        
        // Handle image upload if present
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = 'menu_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('menu-items', $imageName, 'public');
            $data['image_path'] = $path;
        }

        $item = MenuItem::create($data);
        
        $item->load('category');
        $responseData = $item->toArray();
        $responseData['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;

        return response()->json(['success' => true, 'data' => $responseData], 201);
    }

    public function update(UpdateMenuItemRequest $request, $id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('update', $item);
        $data = $request->validated();

        // Handle image upload if present
        if ($request->hasFile('image')) {
            // Delete old image
            if ($item->image_path) {
                Storage::disk('public')->delete($item->image_path);
            }

            $image = $request->file('image');
            $imageName = 'menu_' . $item->id . '_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('menu-items', $imageName, 'public');
            $data['image_path'] = $path;
        }

        $item->update($data);
        $item->load('category');
        
        $responseData = $item->toArray();
        $responseData['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;

        return response()->json(['success' => true, 'data' => $responseData]);
    }

    public function toggleActive($id)
    {
        $item = MenuItem::findOrFail($id);
        $this->authorize('toggleActive', $item);
        $item->is_active = !$item->is_active;
        $item->save();

        $data = $item->toArray();
        $data['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function setAvailability(Request $request, $id)
    {
        $request->validate(['is_available' => ['required','boolean']]);

        $item = MenuItem::findOrFail($id);
        $this->authorize('setAvailability', $item);
        $item->is_available = $request->boolean('is_available');
        $item->save();

        $data = $item->toArray();
        $data['image_url'] = $item->image_path ? url('storage/' . $item->image_path) : null;
        return response()->json(['success' => true, 'data' => $data]);
    }


    
}