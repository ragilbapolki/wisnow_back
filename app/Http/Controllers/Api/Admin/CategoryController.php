<?php
// app/Http/Controllers/Api/Admin/CategoryController.php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->isAdmin()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        try {
            $query = Category::withCount('articles');

            // Search by name
            if ($search = $request->input('search')) {
                $query->where('name', 'like', "%{$search}%");
            }

            // Sorting (default by name ASC)
            $query->orderBy('name', 'asc');

            // Pagination (default 10)
            $perPage = $request->input('per_page', 10);
            $categories = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data'    => $categories->items(),
                'total'   => $categories->total(),
                'current_page' => $categories->currentPage(),
                'per_page'     => $categories->perPage(),
                'last_page'    => $categories->lastPage(),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch categories: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'name' => 'required|string|max:255|unique:categories',
                'slug' => 'required|string|max:255|unique:categories|regex:/^[a-z0-9-]+$/',
                'icon' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
                'status' => 'nullable|in:draft,published'
            ]);

            $categoryData = $request->only(['name', 'slug', 'icon', 'description']);
            $categoryData['status'] = $request->input('status', 'draft');

            $category = Category::create($categoryData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category->loadCount('articles')
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Category $category)
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Category retrieved successfully',
                'data' => $category->loadCount('articles')
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Category $category)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
                'slug' => 'required|string|max:255|unique:categories,slug,' . $category->id . '|regex:/^[a-z0-9-]+$/',
                'icon' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:500',
                'status' => 'nullable|in:draft,published'
            ]);

            $updateData = $request->only(['name', 'slug', 'icon', 'description']);
            if ($request->has('status')) {
                $updateData['status'] = $request->input('status');
            }

            $category->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category->fresh()->loadCount('articles')
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Category $category)
    {
        DB::beginTransaction();

        try {
            // Check if category has articles
            $articlesCount = $category->articles()->count();
            if ($articlesCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete category with {$articlesCount} articles"
                ], 400);
            }

            $categoryName = $category->name;
            $category->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Category '{$categoryName}' deleted successfully"
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete category: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:categories,id'
            ]);

            $categories = Category::whereIn('id', $request->ids)
                ->withCount('articles')
                ->get();

            // Check if any categories have articles
            $categoriesWithArticles = $categories->where('articles_count', '>', 0);
            if ($categoriesWithArticles->count() > 0) {
                $categoryNames = $categoriesWithArticles->pluck('name')->implode(', ');
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete categories with articles: {$categoryNames}"
                ], 400);
            }

            $deletedCount = Category::whereIn('id', $request->ids)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$deletedCount} categories deleted successfully"
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk delete categories: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}