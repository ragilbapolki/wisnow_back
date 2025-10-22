<?php
// app/Http/Controllers/Api/Admin/ArticleController.php
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleController extends Controller
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

    public function store(Request $request)
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

    public function update(Request $request, Article $article)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
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
            'attachment' => 'nullable|file|mimes:pdf|max:10240',
            'remove_attachment' => 'nullable|boolean'
        ]);

        // Handle attachment removal
        if ($request->remove_attachment && $article->attachment_path) {
            Storage::disk('public')->delete($article->attachment_path);
            $article->attachment_path = null;
            $article->attachment_name = null;
            $article->attachment_size = null;
        }

        // Handle new attachment upload
        if ($request->hasFile('attachment')) {
            if ($article->attachment_path) {
                Storage::disk('public')->delete($article->attachment_path);
            }

            $file = $request->file('attachment');
            $fileName = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.pdf';
            $attachmentPath = $file->storeAs('attachments', $fileName, 'public');

            $article->attachment_path = $attachmentPath;
            $article->attachment_name = $file->getClientOriginalName();
            $article->attachment_size = $file->getSize();
        }

        $article->update([
            'title' => $request->title,
            'description' => $request->description,
            'content' => $request->content,
            'type' => $request->type,
            'category_id' => $request->category_id,
            'status' => $request->status,
            'visibility' => $request->visibility,
            'document_type' => $request->document_type,
            'published_at' => $request->status === 'published' && !$article->published_at ? now() : $article->published_at
        ]);

        // Sync divisions and departments
        if ($request->visibility === 'private') {
            if ($request->has('divisions')) {
                $article->divisions()->sync($request->divisions);
            }
            if ($request->has('departments')) {
                $article->departments()->sync($request->departments);
            }
        } else {
            // Clear divisions and departments if changed to public
            $article->divisions()->detach();
            $article->departments()->detach();
        }

        // Update image relationships if provided
        if ($request->has('images')) {
            ArticleGallery::where('article_id', $article->id)
                ->whereNotIn('id', $request->images ?? [])
                ->update(['article_id' => null]);

            if (is_array($request->images) && count($request->images) > 0) {
                ArticleGallery::whereIn('id', $request->images)
                    ->update(['article_id' => $article->id]);
            }
        }

        // Load fresh data
        $article->load(['category', 'user', 'divisions', 'departments']);
        $article->gallery_count = $article->galleries()->count();

        return response()->json([
            'success' => true,
            'data' => $article
        ]);
    }

    public function show($id)
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

        // Check if user can access this article
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

        // Increment view count
        $article->incrementViewCount();

        // Get related articles
        $relatedArticles = Article::with(['category'])
            ->published()
            ->accessible(auth()->user())
            ->where('id', '!=', $article->id)
            ->where('category_id', $article->category_id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Format galleries
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

        // Format attachment info
        if ($article->attachment_path) {
            $article->attachment = [
                'url' => $article->attachment_url,
                'name' => $article->attachment_name,
                'size' => $article->attachment_size,
                'formatted_size' => $article->formatted_attachment_size
            ];
        }

        // TAMBAHKAN VISIBILITY SECARA EKSPLISIT
        $article->makeVisible(['visibility']); // Jika hidden

        return response()->json([
            'success' => true,
            'article' => $article,
            'related_articles' => $relatedArticles
        ]);
    }

    public function destroy(Article $article)
    {
        // Delete attachment if exists
        if ($article->attachment_path) {
            Storage::disk('public')->delete($article->attachment_path);
        }

        // Delete gallery images if exist
        if ($article->galleries) {
            foreach ($article->galleries as $gallery) {
                Storage::disk('public')->delete($gallery->path);
            }
        }

        $article->delete();

        return response()->json(['message' => 'Article deleted successfully']);
    }

    public function downloadAttachment(Article $article)
    {
        if (!$article->attachment_path || !Storage::disk('public')->exists($article->attachment_path)) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        return Storage::disk('public')->download(
            $article->attachment_path,
            $article->attachment_name
        );
    }
}
