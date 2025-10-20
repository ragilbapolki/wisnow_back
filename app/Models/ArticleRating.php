<?php
// app/Models/ArticleRating.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id', 'user_id', 'rating', 'comment'
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($rating) {
            $rating->article->updateRating();
        });

        static::updated(function ($rating) {
            $rating->article->updateRating();
        });

        static::deleted(function ($rating) {
            $rating->article->updateRating();
        });
    }

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
