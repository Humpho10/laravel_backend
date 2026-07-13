<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\SubCategory;
use App\Helpers\AuditLogger;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * @var \App\Models\User|null
     */
    protected $user;

    /**
     * Constructor – set the authenticated user once.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            /** @var \App\Models\User $user */
            $this->user = $request->user();
            return $next($request);
        });
    }

    // ── PUBLIC — INDEX ─────────────────────────────────────────
    // No permission check – public route for browsing
    public function index()
    {
        $categories = Category::with('subcategories')
                        ->orderBy('name')
                        ->get();

        return response()->json([
            'categories' => $categories,
        ], 200);
    }

    // ── PUBLIC — SUBCATEGORIES FOR DROPDOWNS ──────────────────
    // No permission check – public route for browsing
    public function subcategories($categoryId)
    {
        $category = Category::where('category_id', $categoryId)->first();

        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $subcategories = SubCategory::where('category_id', $categoryId)
                            ->orderBy('sub_category_name')
                            ->get();

        return response()->json([
            'category'      => $category->name,
            'subcategories' => $subcategories,
        ], 200);
    }

    // ── ADMIN — STORE CATEGORY ─────────────────────────────────
    public function store(Request $request)
    {
        if (!$this->user->can('category-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create categories.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
        ]);

        $category = Category::create(['name' => $validated['name']]);

        AuditLogger::log('categories', $category->category_id, 'created', null, $category->toArray());

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    // ── ADMIN — UPDATE CATEGORY ─────────────────────────────────
    public function update(Request $request, $id)
    {
        if (!$this->user->can('category-edit')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to update categories.'], 403);
        }

        $category = Category::where('category_id', $id)->first();

        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name,' . $id . ',category_id',
        ]);

        $oldValue = $category->toArray();
        $category->update($validated);

        AuditLogger::log('categories', $category->category_id, 'updated', $oldValue, $category->toArray());

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $category,
        ], 200);
    }

    // ── ADMIN — DESTROY CATEGORY ────────────────────────────────
    public function destroy($id)
    {
        if (!$this->user->can('category-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete categories.'], 403);
        }

        $category = Category::where('category_id', $id)->first();

        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        // Business rule: Cannot delete category with products
        if ($category->products()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete a category that has products under it.'
            ], 400);
        }

        $oldValue = $category->toArray();
        $category->delete();

        AuditLogger::log('categories', $id, 'deleted', $oldValue, null);

        return response()->json([
            'message' => 'Category deleted successfully.'
        ], 200);
    }

    // ── ADMIN — STORE SUBCATEGORY ──────────────────────────────
    public function storeSubcategory(Request $request, $categoryId)
    {
        if (!$this->user->can('category-create')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to create subcategories.'], 403);
        }

        $category = Category::where('category_id', $categoryId)->first();

        if (!$category) {
            return response()->json(['error' => 'Category not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $exists = SubCategory::where('category_id', $categoryId)
                    ->where('sub_category_name', $validated['name'])
                    ->exists();

        if ($exists) {
            return response()->json([
                'error' => 'This subcategory already exists under this category.'
            ], 400);
        }

        $subcategory = SubCategory::create([
            'category_id'       => $categoryId,
            'sub_category_name' => $validated['name'],
        ]);

        AuditLogger::log('subcategories', $subcategory->subcategory_id, 'created', null, $subcategory->toArray());

        return response()->json([
            'message'     => 'Subcategory created successfully.',
            'subcategory' => $subcategory,
        ], 201);
    }

    // ── ADMIN — DESTROY SUBCATEGORY ────────────────────────────
    public function destroySubcategory($id)
    {
        if (!$this->user->can('category-delete')) {
            return response()->json(['message' => 'Unauthorized. You do not have permission to delete subcategories.'], 403);
        }

        $subcategory = SubCategory::where('subcategory_id', $id)->first();

        if (!$subcategory) {
            return response()->json(['error' => 'Subcategory not found.'], 404);
        }

        // Business rule: Cannot delete subcategory with products
        if ($subcategory->products()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete a subcategory that has products under it.'
            ], 400);
        }

        $oldValue = $subcategory->toArray();
        $subcategory->delete();

        AuditLogger::log('subcategories', $id, 'deleted', $oldValue, null);

        return response()->json([
            'message' => 'Subcategory deleted successfully.'
        ], 200);
    }
}