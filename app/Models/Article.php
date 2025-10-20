<?php
// app/Models/Article.php (Fixed)
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'content',
        'type',
        'category_id',
        'user_id',
        'status',
        'document_type',
        'published_at',
        'views_count',
        'slug',
        'view_count',
        'rating',
        'rating_count',
        'gallery_count',
        'attachment_path'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'views_count' => 'integer',
        'view_count' => 'integer',
        'rating' => 'float',
        'rating_count' => 'integer',
        'gallery_count' => 'integer'
    ];

    protected $appends = [
        'primary_image',
        'excerpt',
        'attachment_url'
    ];

    /**
     * Relationship with Category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relationship with User (author)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relationship with Article Gallery
     */
    public function gallery()
    {
        return $this->hasMany(ArticleGallery::class);
    }

    /**
     * Relationship with Ratings
     */
    public function ratings()
    {
        return $this->hasMany(ArticleRating::class);
    }

    /**
     * Get the primary gallery image
     */
    public function primaryImage()
    {
        return $this->hasOne(ArticleGallery::class)->where('is_primary', true);
    }

    /**
     * Get primary image URL attribute
     */
    public function getPrimaryImageAttribute()
    {
        $primaryImage = $this->primaryImage()->first();
        return $primaryImage ? $primaryImage->full_url : null;
    }

    public function getGalleryCountAttribute()
    {
        return $this->galleries()->count();
    }

    public function galleries()
    {
        return $this->hasMany(ArticleGallery::class)->orderBy('sort_order', 'asc');
    }

    /**
     * Get article excerpt
     */
    public function getExcerptAttribute()
    {
        if ($this->description) {
            return strlen($this->description) > 150
                ? substr($this->description, 0, 150) . '...'
                : $this->description;
        }

        return strlen($this->content) > 200
            ? substr(strip_tags($this->content), 0, 200) . '...'
            : strip_tags($this->content);
    }

    /**
     * Scope for published articles
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
    }

    /**
     * Scope for draft articles
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope by category
     */
    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Search scope
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'LIKE', "%{$search}%")
              ->orWhere('description', 'LIKE', "%{$search}%")
              ->orWhere('content', 'LIKE', "%{$search}%");
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (!$article->slug && $article->title) {
                $article->slug = \Str::slug($article->title);
            }
            if ($article->status === 'published' && !$article->published_at) {
                $article->published_at = now();
            }
        });

        static::updating(function ($article) {
            if ($article->isDirty('status') && $article->status === 'published' && !$article->published_at) {
                $article->published_at = now();
            }
            if ($article->isDirty('status') && $article->status === 'draft') {
                $article->published_at = null;
            }
        });

        static::deleting(function ($article) {
            $article->gallery()->each(function ($image) {
                if (\Storage::disk('public')->exists($image->path)) {
                    \Storage::disk('public')->delete($image->path);
                }
                $image->delete();
            });
        });
    }

    public function views()
    {
        return $this->hasMany(ArticleView::class);
    }

    public function incrementViewCount($userId = null, $ipAddress = null)
    {
        $alreadyViewed = $this->views()
            ->where(function ($query) use ($userId, $ipAddress) {
                if ($userId) {
                    $query->where('user_id', $userId);
                } else {
                    $query->where('ip_address', $ipAddress);
                }
            })
            ->whereDate('viewed_at', today())
            ->exists();

        if (!$alreadyViewed) {
            $this->views()->create([
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'viewed_at' => now(),
            ]);

            $this->increment('view_count');
        }
    }
    public function updateRating()
    {
        $ratings = $this->ratings()
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as count')
            ->first();

        $this->update([
            'rating' => round($ratings->avg_rating ?? 0, 1),
            'rating_count' => $ratings->count ?? 0
        ]);

        return $ratings->avg_rating;
    }

    public function getAttachmentUrlAttribute()
    {
        return $this->attachment_path
            ? asset('storage/' . $this->attachment_path)
            : null;
    }

    public function getFormattedAttachmentSizeAttribute()
    {
        if (!$this->attachment_size) {
            return null;
        }

        return $this->formatBytes($this->attachment_size);
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

}
