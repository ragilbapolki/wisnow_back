<?php
// app/Http/Controllers/Api/ArticleController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with(['category', 'user'])->published();

        // All your filters...
        if ($request->category) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'LIKE', '%' . $request->search . '%')
                ->orWhere('description', 'LIKE', '%' . $request->search . '%')
                ->orWhere('content', 'LIKE', '%' . $request->search . '%');
            });
        }

        $sort = $request->input('sort', 'latest');
        switch ($sort) {
            case 'popular':
                $query->orderBy('view_count', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating', 'desc')->orderBy('rating_count', 'desc');
                break;
            case 'latest':
            default:
                $query->orderBy('published_at', 'desc')->orderBy('created_at', 'desc');
                break;
        }

        // Non-paginated
        $limit = $request->input('limit');
        if ($limit) {
            $articles = $query->limit($limit)->get()->map(function ($article) {
                return $this->formatArticleResponse($article);
            });
            return response()->json(['data' => $articles]);
        }

        // ✅ PAGINATION dengan metadata eksplisit
        $perPage = $request->input('per_page', 12);
        $page = $request->input('page', 1);

        // Paginate
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        // Format articles
        $formattedArticles = $paginated->getCollection()->map(function ($article) {
            return $this->formatArticleResponse($article);
        });

        // ✅ Build response dengan semua metadata
        return response()->json([
            'data' => $formattedArticles->values(),
            'current_page' => $paginated->currentPage(),
            'first_page_url' => $paginated->url(1),
            'from' => $paginated->firstItem(),
            'last_page' => $paginated->lastPage(),
            'last_page_url' => $paginated->url($paginated->lastPage()),
            'next_page_url' => $paginated->nextPageUrl(),
            'path' => $paginated->path(),
            'per_page' => $paginated->perPage(),
            'prev_page_url' => $paginated->previousPageUrl(),
            'to' => $paginated->lastItem(),
            'total' => $paginated->total(),
        ]);
    }

    public function show($slug, Request $request)
    {
        $article = Article::with(['category', 'user', 'ratings.user'])
            ->where('slug', $slug)
            ->published()
            ->firstOrFail();

        // Increment view count
        $article->incrementViewCount(
            auth()->id(),
            $request->ip()
        );

        $article->refresh();

        // Get related articles
        $relatedArticles = Article::published()
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->orderBy('view_count', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($relatedArticle) {
                return $this->formatArticleResponse($relatedArticle);
            });

        return response()->json([
            'article' => $this->formatArticleResponse($article),
            'related_articles' => $relatedArticles
        ]);
    }

    public function rate(Request $request, Article $article)
    {
        if (!auth()->check()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $request->validate([
            'rating.rating' => 'required|integer|min:1|max:5',
            'rating.comment' => 'nullable|string|max:1000',
        ]);
        $ratingData = $request->rating;
        $rating = $ratingData['rating'] ?? null;
        $comment = $ratingData['comment'] ?? null;
        $existingRating = $article->ratings()
            ->where('user_id', auth()->id())
            ->first();
        if ($existingRating) {
            $existingRating->update([
                'rating' => $rating,
                'comment' => $comment
            ]);
        } else {
            $article->ratings()->create([
                'user_id' => auth()->id(),
                'rating' => $rating,
                'comment' => $comment
            ]);
        }
        $article->updateRating();
        return response()->json(['message' => 'Rating berhasil disimpan']);
    }

    public function uploadGallery(Request $request)
    {
        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);

        $uploadedImages = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('gallery', 'public');
                $uploadedImages[] = [
                    'url' => Storage::url($path),
                    'filename' => $image->getClientOriginalName()
                ];
            }
        }

        return response()->json(['images' => $uploadedImages]);
    }

    /**
     * Format article response dengan type casting yang konsisten
     */
    private function formatArticleResponse($article)
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'description' => $article->description,
            'content' => $article->content ?? '',
            'type' => $article->type,
            'document_type' => $article->document_type,
            'status' => $article->status,
            'view_count' => (int) ($article->view_count ?? 0),
            'rating' => (float) ($article->rating ?? 0.0),
            'rating_count' => (int) ($article->rating_count ?? 0),
            'gallery' => $article->gallery ?? [],
            'gallery_count' => (int) ($article->gallery_count ?? 0),
            'published_at' => $article->published_at,
            'attachment_path' => $article->attachment_path,
            'attachment_name' => $article->attachment_name,
            'attachment_size' => $article->attachment_size,
            'created_at' => $article->created_at,
            'updated_at' => $article->updated_at,
            'category' => $article->category ? [
                'id' => $article->category->id,
                'name' => $article->category->name,
                'slug' => $article->category->slug,
                'icon' => $article->category->icon,
            ] : null,
            'user' => $article->user ? [
                'id' => $article->user->id,
                'name' => $article->user->name,
                'email' => $article->user->email,
                'avatar' => $article->user->avatar,
                'role' => $article->user->role,
                'position' => $article->user->position
            ] : null,
        ];
    }

    /**
     * Update article rating average
     */
    private function updateArticleRating(Article $article)
    {
        $ratings = $article->ratings()
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        $article->update([
            'rating' => round($ratings->avg_rating ?? 0, 1),
            'rating_count' => $ratings->count ?? 0
        ]);
    }

    public function downloadAttachment(Article $article)
    {
        if (!$article->attachment_path) {
            return response()->json([
                'success' => false,
                'message' => 'Lampiran tidak ditemukan'
            ], 404);
        }

        if (!Storage::disk('public')->exists($article->attachment_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan di server'
            ], 404);
        }

        // Return file download response
        return Storage::disk('public')->download(
            $article->attachment_path,
            $article->attachment_name ?? 'dokumen.pdf',
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }
}
