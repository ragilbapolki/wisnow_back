<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ArticleGallery extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'filename',
        'original_name',
        'path',
        'url',
        'mime_type',
        'size',
        'width',
        'height',
        'alt_text',
        'caption',
        'is_primary',
        'uploaded_by',
        'session_key',
        'is_temporary'
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    // Relationship
    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // Accessors
    public function getFullUrlAttribute()
    {
        return $this->url ? url($this->url) : null;
    }

    public function getFormattedSizeAttribute()
    {
        return $this->formatFileSize($this->size);
    }

    public function getDimensionsAttribute()
    {
        if ($this->width && $this->height) {
            return "{$this->width}x{$this->height}";
        }
        return null;
    }

    // Helper methods
    private function formatFileSize($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function delete()
    {
        // Delete file when model is deleted
        if ($this->path && Storage::disk('public')->exists($this->path)) {
            Storage::disk('public')->delete($this->path);
        }

        return parent::delete();
    }

}