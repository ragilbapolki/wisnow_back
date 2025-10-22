<?php
// app/Models/ArticleDepartment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ArticleDepartment extends Pivot
{
    protected $table = 'article_departments';

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function department()
    {
        return $this->belongsTo(MDivision::class);
    }
}
