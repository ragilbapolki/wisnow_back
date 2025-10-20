<?php

// app/Http/Controllers/Api/Admin/DashboardController.php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
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

    public function index()
    {
        $stats = [
            'total_articles' => Article::count(),
            'published_articles' => Article::published()->count(),
            'total_categories' => Category::count(),
            'total_users' => User::count(),
        ];

        $mostViewedArticles = Article::published()
            ->mostViewed(5)
            ->with(['category', 'user'])
            ->get();

        $highestRatedArticles = Article::published()
            ->highestRated(5)
            ->with(['category', 'user'])
            ->get();

        $recentlyViewedArticles = Article::published()
            ->recentlyViewed(5)
            ->with(['category', 'user'])
            ->get();

        return response()->json([
            'stats' => $stats,
            'most_viewed' => $mostViewedArticles,
            'highest_rated' => $highestRatedArticles,
            'recently_viewed' => $recentlyViewedArticles
        ]);
    }
}