<?php
// app/Http/Controllers/Api/ArticleController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Article;
use App\Models\Category;
use App\Models\ArticleGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request)
    {
        $query = Article::with(['category', 'user'])->published();

        if ($request->category) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->penulis) {
            $query->where('user_id', $request->penulis);
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

        $limit = $request->input('limit');
        if ($limit) {
            $articles = $query->limit($limit)->get()->map(function ($article) {
                return $this->formatArticleResponse($article);
            });
            return response()->json(['data' => $articles]);
        }

        $perPage = $request->input('per_page', 12);
        $page = $request->input('page', 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $formattedArticles = $paginated->getCollection()->map(function ($article) {
            return $this->formatArticleResponse($article);
        });

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

    public function showSlug($slug, Request $request)
    {
        $article = Article::with([
            'category',
            'user',
            'ratings.user',
            'divisions',
            'departments'
        ])
        ->where('slug', $slug)
        ->published()
        ->firstOrFail();

        if (!$article->userCanAccess(auth()->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke artikel ini.',
                'required_access' => [
                    'visibility' => $article->visibility,
                    'divisions' => $article->divisions->pluck('name'),
                    'departments' => $article->departments->pluck('name')
                ]
            ], 403);
        }

        $article->incrementViewCount(auth()->id(), $request->ip());
        $article->refresh();

        $relatedArticles = Article::published()
            ->with(['category', 'user'])
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->orderBy('view_count', 'desc')
            ->limit(10)
            ->get()
            ->filter(function ($relatedArticle) {
                return $relatedArticle->userCanAccess(auth()->user());
            })
            ->take(3)
            ->map(function ($relatedArticle) {
                return $this->formatArticleResponse($relatedArticle);
            });

        return response()->json([
            'article' => $this->formatArticleResponse($article, true),
            'related_articles' => $relatedArticles->values()
        ]);
    }

    /**
     * Rate an article - Enhanced version
     */
    public function rate(Request $request, Article $article)
    {
        $input = $request->all();

        if (is_array($input['rating'])) {
            $request->merge([
                'rating' => $input['rating']['rating'] ?? null,
                'comment' => $input['rating']['comment'] ?? null,
            ]);
        }
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $rating = $request->input('rating');
        $comment = $request->input('comment');

        $existingRating = $article->ratings()
            ->where('user_id', auth()->id())
            ->first();

        if ($existingRating) {
            $existingRating->update([
                'rating' => $rating,
                'comment' => $comment
            ]);

            $message = 'Rating dan komentar berhasil diperbarui';
        } else {
            $article->ratings()->create([
                'user_id' => auth()->id(),
                'rating' => $rating,
                'comment' => $comment
            ]);

            $message = 'Rating dan komentar berhasil disimpan';
        }

        // Update article rating statistics
        $this->updateArticleRating($article);

        // Reload article with updated ratings
        $article->refresh();
        $article->load(['ratings.user', 'category', 'user', 'divisions', 'departments']);

        return response()->json([
            'success' => true,
            'message' => $message,
            'article' => $this->formatArticleResponse($article, true)
        ]);
    }

    /**
     * Get rating statistics for an article
     */
    public function getRatingStats(Article $article)
    {
        $stats = $article->ratings()
            ->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->get()
            ->keyBy('rating');

        $distribution = [];
        for ($i = 5; $i >= 1; $i--) {
            $distribution[$i] = $stats->get($i)?->count ?? 0;
        }

        $totalRatings = array_sum($distribution);
        $averageRating = $totalRatings > 0
            ? round(array_sum(array_map(fn($star, $count) => $star * $count, array_keys($distribution), $distribution)) / $totalRatings, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'distribution' => $distribution,
                'total_ratings' => $totalRatings,
                'average_rating' => $averageRating,
                'rating_percentages' => array_map(
                    fn($count) => $totalRatings > 0 ? round(($count / $totalRatings) * 100, 1) : 0,
                    $distribution
                )
            ]
        ]);
    }

    /**
     * Format article response dengan type casting yang konsisten
     */
    private function formatArticleResponse($article, $includeFullRatings = false)
    {
        $response = [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'description' => $article->description,
            'content' => $article->content ?? '',
            'type' => $article->type,
            'document_type' => $article->document_type,
            'status' => $article->status,
            'category_id' => $article->category_id,
            'visibility' => $article->visibility ?? 'public',
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

            'divisions' => $article->divisions ? $article->divisions->map(function ($div) {
                return [
                    'id' => $div->id,
                    'name' => $div->name,
                ];
            }) : [],

            'departments' => $article->departments ? $article->departments->map(function ($dept) {
                return [
                    'id' => $dept->id,
                    'name' => $dept->name,
                ];
            }) : [],

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
                'position' => $article->user->position,
                'avatar_url' => $article->user->avatar_url
            ] : null,
        ];

        // Include full ratings details if requested (untuk detail page)
        if ($includeFullRatings && $article->relationLoaded('ratings')) {
            $response['ratings'] = $article->ratings->map(function ($rating) {
                return [
                    'id' => $rating->id,
                    'rating' => (int) $rating->rating,
                    'comment' => $rating->comment,
                    'created_at' => $rating->created_at,
                    'updated_at' => $rating->updated_at,
                    'user' => $rating->user ? [
                        'id' => $rating->user->id,
                        'name' => $rating->user->name,
                        'avatar' => $rating->user->avatar,
                        'avatar_url' => $rating->user->avatar_url,
                    ] : null
                ];
            })->sortByDesc('created_at')->values();

            // Add rating distribution untuk stats
            $ratingStats = $article->ratings
                ->groupBy('rating')
                ->map(fn($group) => $group->count());

            $response['rating_distribution'] = [
                5 => $ratingStats->get(5, 0),
                4 => $ratingStats->get(4, 0),
                3 => $ratingStats->get(3, 0),
                2 => $ratingStats->get(2, 0),
                1 => $ratingStats->get(1, 0),
            ];
        }

        return $response;
    }

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

        return Storage::disk('public')->download(
            $article->attachment_path,
            $article->attachment_name ?? 'dokumen.pdf',
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }

    public function indexPenulis()
    {
        $penulis = User::select('users.*')
            ->withCount(['articles' => function ($query) {
                $query->where('status', 'published');
            }])
            ->where('role', 'editor')
            ->get()
            ->filter(function ($penulis) {
                return $penulis->articles_count > 0;
            })
            ->sortByDesc('articles_count')
            ->values()
            ->map(function ($penulis) {
                return [
                    'id' => $penulis->id,
                    'name' => $penulis->name,
                    'position' => $penulis->position,
                    'role' => $penulis->role,
                    'avatar' => $penulis->avatar,
                    'moto' => $penulis->moto,
                    'articles_count' => (int) $penulis->articles_count,
                    'created_at' => $penulis->created_at,
                    'updated_at' => $penulis->updated_at,
                ];
            });

        return response()->json($penulis);
    }

    public function storeEditor(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:SOP,Kebijakan,Panduan',
            'category_id' => 'required|exists:categories,id',
            'status' => 'required|in:draft,published',
            'visibility' => 'required|in:public,private',
            'divisions' => 'required_if:visibility,private|array',
            'divisions.*' => 'exists:m_division,id',
            'departments' => 'required_if:visibility,private|array',
            'departments.*' => 'exists:m_division,id',
            'document_type' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'integer|exists:article_galleries,id',
            'attachment' => 'nullable|file|mimes:pdf|max:10240'
        ]);

        // Handle PDF attachment upload
        $attachmentPath = null;
        $attachmentName = null;
        $attachmentSize = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $fileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.pdf';
            $attachmentPath = $file->storeAs('attachments', $fileName, 'public');
            $attachmentName = $file->getClientOriginalName();
            $attachmentSize = $file->getSize();
        }

        $article = Article::create([
            'title' => $request->title,
            'description' => $request->description,
            'content' => $request->content,
            'type' => $request->type,
            'category_id' => $request->category_id,
            'user_id' => auth()->id(),
            'status' => $request->status,
            'visibility' => $request->visibility,
            'document_type' => $request->document_type,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_size' => $attachmentSize,
            'published_at' => $request->status === 'published' ? now() : null
        ]);

        // Sync divisions and departments if private
        if ($request->visibility === 'private') {
            if ($request->has('divisions')) {
                $article->divisions()->sync($request->divisions);
            }
            if ($request->has('departments')) {
                $article->departments()->sync($request->departments);
            }
        }

        // Link existing images to this article if provided
        if ($request->has('images') && is_array($request->images)) {
            ArticleGallery::whereIn('id', $request->images)
                ->whereNull('article_id')
                ->update(['article_id' => $article->id]);
        }

        // Load the article with relationships
        $article->load(['category', 'user', 'divisions', 'departments']);
        $article->gallery_count = $article->galleries()->count();

        return response()->json([
            'success' => true,
            'data' => $article,
            'id' => $article->id
        ], 201);
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

                // Create gallery record
                $gallery = ArticleGallery::create([
                    'article_id' => null, // Will be linked when article is saved
                    'path' => $path,
                    'alt_text' => null,
                    'caption' => null,
                    'is_primary' => false
                ]);

                $uploadedImages[] = [
                    'id' => $gallery->id,
                    'url' => Storage::url($path),
                    'path' => $path,
                    'filename' => $image->getClientOriginalName()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'images' => $uploadedImages
        ]);
    }

    public function indexEditor(Request $request)
    {
        $user = auth()->user();
        $query = Article::with(['category', 'user', 'divisions', 'departments'])
            ->published();

        if ($user && $user->role === 'editor') {
            $query->where('user_id', $user->id);
        }

        if ($request->has('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('visibility')) {
            $query->where('visibility', $request->visibility);
        }

        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->has('check_access') && $request->check_access) {
            $query->accessible($user);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if ($sortBy === 'popular') {
            $query->orderBy('view_count', 'desc');
        } elseif ($sortBy === 'rating') {
            $query->orderBy('rating', 'desc');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $articles = $query->paginate($request->get('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => $articles
        ]);
    }

    public function show($id, Request $request)
    {
        $article = Article::with([
            'category',
            'user',
            'ratings.user',
            'divisions',
            'departments'
        ])
        ->where('id', $id)
        ->published()
        ->firstOrFail();

        if (!$article->userCanAccess(auth()->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke artikel ini.',
                'required_access' => [
                    'visibility' => $article->visibility,
                    'divisions' => $article->divisions->pluck('name'),
                    'departments' => $article->departments->pluck('name')
                ]
            ], 403);
        }

        $article->incrementViewCount(auth()->id(), $request->ip());
        $article->refresh();

        $relatedArticles = Article::published()
            ->with(['category', 'user'])
            ->where('category_id', $article->category_id)
            ->where('id', '!=', $article->id)
            ->orderBy('view_count', 'desc')
            ->limit(10)
            ->get()
            ->filter(function ($relatedArticle) {
                return $relatedArticle->userCanAccess(auth()->user());
            })
            ->take(3)
            ->map(function ($relatedArticle) {
                return $this->formatArticleResponse($relatedArticle);
            });

        return response()->json([
            'article' => $this->formatArticleResponse($article, true),
            'related_articles' => $relatedArticles->values()
        ]);
    }

    public function showEditor($id)
    {
        $article = Article::with([
            'category',
            'user',
            'galleries',
            'ratings.user',
            'divisions',
            'departments'
        ])
        ->where('id', $id)
        ->published()
        ->firstOrFail();

        if (!$article->can_access) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this article',
                'article' => [
                    'visibility' => $article->visibility,
                    'divisions' => $article->divisions,
                    'departments' => $article->departments
                ]
            ], 403);
        }

        $article->incrementViewCount();

        $relatedArticles = Article::with(['category'])
            ->published()
            ->accessible(auth()->user())
            ->where('id', '!=', $article->id)
            ->where('category_id', $article->category_id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        $article->gallery = $article->galleries->map(function ($gallery) {
            return [
                'id' => $gallery->id,
                'url' => $gallery->full_url,
                'path' => $gallery->path,
                'alt_text' => $gallery->alt_text,
                'caption' => $gallery->caption,
                'is_primary' => $gallery->is_primary
            ];
        });

        if ($article->attachment_path) {
            $article->attachment = [
                'url' => $article->attachment_url,
                'name' => $article->attachment_name,
                'size' => $article->attachment_size,
                'formatted_size' => $article->formatted_attachment_size
            ];
        }

        $article->makeVisible(['visibility']);

        return response()->json([
            'success' => true,
            'article' => $article,
            'related_articles' => $relatedArticles
        ]);
    }
}
