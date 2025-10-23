<?php
// app/Http/Controllers/Api/CategoryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
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

    public function show($slug)
    {
        $category = Category::where('slug', $slug)
            ->withCount([
                'articles' => function ($query) {
                    $query->where('status', 'published');
                }
            ])
            ->firstOrFail();

        return response()->json([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'icon' => $category->icon,
            'description' => $category->description,
            'articles_count' => (int) $category->articles_count,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ]);
    }
}
