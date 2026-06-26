<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PublicMenuController extends Controller
{
    /**
     * Get all menu items
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::with('category')
            ->where('is_active', true);
            ->where('is_available', true);

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by category if provided
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search if provided
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $items = $query->get();

        // Transform items to include full image URL
        $items->transform(function ($item) {
            if ($item->image_path) {
                $item->image_url = url('storage/' . $item->image_path);
            } else {
                $item->image_url = null;
            }
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'message' => 'Menu items retrieved successfully'
        ]);
    }

    /**
     * Get all active categories
     */
    public function categories(): JsonResponse
    {
        $categories = MenuCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Transform categories to include full image URL
        $categories->transform(function ($category) {
            if ($category->image_path) {
                $category->image_url = url('storage/' . $category->image_path);
            } else {
                $category->image_url = null;
            }
            return $category;
        });

        return response()->json([
            'success' => true,
            'data' => $categories,
            'message' => 'Categories retrieved successfully'
        ]);
    }

    /**
     * Get single menu item by ID
     */
    public function show($id): JsonResponse
    {
        $item = MenuItem::with('category')
            ->where('id', $id)
            ->where('is_active', true)
            ->where('is_available', true)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Menu item not found'
            ], 404);
        }

        // Add full image URL
        if ($item->image_path) {
            $item->image_url = url('storage/' . $item->image_path);
        } else {
            $item->image_url = null;
        }

        return response()->json([
            'success' => true,
            'data' => $item,
            'message' => 'Menu item retrieved successfully'
        ]);
    }
}
