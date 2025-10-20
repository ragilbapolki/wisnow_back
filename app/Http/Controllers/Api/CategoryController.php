<?php
// app/Http/Controllers/Api/CategoryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::select('categories.*')
            ->withCount([
                'articles' => function ($query) {
                    $query->where('status', 'published');
                }
            ])
            ->having('articles_count', '>', 0)
            ->orderBy('articles_count', 'desc')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'description' => $category->description,
                    'articles_count' => (int) $category->articles_count,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ];
            });

        return response()->json($categories);
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
