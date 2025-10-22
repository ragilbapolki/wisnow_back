<?php
// app/Models/ArticleDivision.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ArticleDivision extends Pivot
{
    protected $table = 'article_divisions';

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function division()
    {
        return $this->belongsTo(MDivision::class);
    }
}
