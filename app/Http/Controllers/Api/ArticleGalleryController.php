<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleGallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ArticleGalleryController extends Controller
{
    /**
     * Upload image for article
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */

    // public function getArticleImages($articleId)
    // {
    //     $images = ArticleGallery::where('article_id', $articleId)
    //         ->where('is_temporary', false)
    //         ->orderBy('sort_order')
    //         ->orderBy('created_at')
    //         ->get()
    //         ->map(function ($image) {
    //             return [
    //                 'id' => $image->id,
    //                 'filename' => $image->filename,
    //                 'original_name' => $image->original_name,
    //                 'path' => $image->path,
    //                 'url' => asset('storage/' . $image->path), // URL lengkap
    //                 'mime_type' => $image->mime_type,
    //                 'size' => $image->size,
    //                 'width' => $image->width,
    //                 'height' => $image->height,
    //                 'alt_text' => $image->alt_text,
    //                 'caption' => $image->caption,
    //                 'is_primary' => $image->is_primary,
    //                 'sort_order' => $image->sort_order,
    //             ];
    //         });

    //     return response()->json([
    //         'status' => 'success',
    //         'data' => $images
    //     ]);
    // }

    // Method untuk menghubungkan temporary images ke article
    public function linkTemporaryImages(Request $request)
    {
        $request->validate([
            'article_id' => 'required|exists:articles,id',
            'session_key' => 'required|string'
        ]);

        try {
            $articleId = $request->article_id;
            $sessionKey = $request->session_key;

            // Get temporary images for this session
            $temporaryImages = ArticleGallery::where('session_key', $sessionKey)
                ->where('is_temporary', true)
                ->whereNull('article_id')
                ->get();

            if ($temporaryImages->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No temporary images to link',
                    'linked_count' => 0
                ]);
            }

            $linkedCount = 0;

            foreach ($temporaryImages as $image) {
                // Move file to proper location
                $oldPath = $image->path;
                $newDirectory = "articles/{$articleId}/gallery";
                $newPath = $newDirectory . '/' . $image->filename;

                // Create new directory if not exists
                Storage::disk('public')->makeDirectory($newDirectory);

                // Move file
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->move($oldPath, $newPath);

                    // Update database record
                    $image->update([
                        'article_id' => $articleId,
                        'path' => $newPath,
                        'url' => Storage::url($newPath),
                        'session_key' => null,
                        'is_temporary' => false
                    ]);

                    $linkedCount++;
                }
            }

            // Clean up temporary directory
            $tempDir = "temp/articles/{$sessionKey}";
            if (Storage::disk('public')->exists($tempDir)) {
                Storage::disk('public')->deleteDirectory($tempDir);
            }

            // Update article gallery count
            $galleryCount = ArticleGallery::where('article_id', $articleId)->count();
            Article::where('id', $articleId)->update(['gallery_count' => $galleryCount]);

            return response()->json([
                'success' => true,
                'message' => "Successfully linked {$linkedCount} images to article",
                'linked_count' => $linkedCount,
                'total_gallery_count' => $galleryCount
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to link temporary images: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to link images: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper method untuk format file size
    private function formatFileSize($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get all images for an article
     *
     * @param int $articleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getArticleImages($articleId)
    {
        try {
            // Verify article exists
            $article = Article::findOrFail($articleId);

            $images = ArticleGallery::where('article_id', $articleId)
                // ->ordered()
                ->get()
                ->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'url' => $image->full_url,
                        'name' => $image->original_name,
                        'size' => $image->size,
                        'formatted_size' => $image->formatted_size,
                        'dimensions' => $image->dimensions,
                        'alt_text' => $image->alt_text,
                        'caption' => $image->caption,
                        'is_primary' => $image->is_primary,
                        'sort_order' => $image->sort_order,
                        'created_at' => $image->created_at->toISOString()
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $images,
                'count' => $images->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update image details
     *
     * @param Request $request
     * @param int $imageId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateImage(Request $request, $imageId)
    {
        $validator = Validator::make($request->all(), [
            'alt_text' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:500',
            'is_primary' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $gallery = ArticleGallery::findOrFail($imageId);

            // Update the fields
            $gallery->update($request->only([
                'alt_text',
                'caption',
                'is_primary',
                'sort_order'
            ]));

            // If this is set as primary, unset other primary images for this article
            if ($request->boolean('is_primary') && $gallery->article_id) {
                ArticleGallery::where('article_id', $gallery->article_id)
                    ->where('id', '!=', $gallery->id)
                    ->update(['is_primary' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Image updated successfully',
                'data' => [
                    'id' => $gallery->id,
                    'url' => $gallery->full_url,
                    'alt_text' => $gallery->alt_text,
                    'caption' => $gallery->caption,
                    'is_primary' => $gallery->is_primary,
                    'sort_order' => $gallery->sort_order
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an image
     *
     * @param int $imageId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImage($imageId)
    {
        try {
            $gallery = ArticleGallery::findOrFail($imageId);
            $articleId = $gallery->article_id;
            $wasPrimary = $gallery->is_primary;

            // Delete file from storage
            if ($gallery->path && Storage::disk('public')->exists($gallery->path)) {
                Storage::disk('public')->delete($gallery->path);
            }

            // Delete database record
            $gallery->delete();

            // If this was the primary image, set another image as primary
            if ($wasPrimary && $articleId) {
                $nextPrimary = ArticleGallery::where('article_id', $articleId)
                    ->orderBy('sort_order', 'asc')
                    ->first();

                if ($nextPrimary) {
                    $nextPrimary->update(['is_primary' => true]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Image deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Delete failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder images
     *
     * @param Request $request
     * @param int $articleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function reorderImages(Request $request, $articleId)
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array',
            'images.*.id' => 'required|exists:article_galleries,id',
            'images.*.sort_order' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verify article exists
            Article::findOrFail($articleId);

            foreach ($request->input('images') as $imageData) {
                ArticleGallery::where('id', $imageData['id'])
                    ->where('article_id', $articleId)
                    ->update(['sort_order' => $imageData['sort_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Images reordered successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Reorder failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set primary image
     *
     * @param Request $request
     * @param int $imageId
     * @return \Illuminate\Http\JsonResponse
     */
    public function setPrimaryImage($imageId)
    {
        try {
            $gallery = ArticleGallery::findOrFail($imageId);

            // Unset all primary images for this article
            if ($gallery->article_id) {
                ArticleGallery::where('article_id', $gallery->article_id)
                    ->update(['is_primary' => false]);
            }

            // Set this image as primary
            $gallery->update(['is_primary' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Primary image set successfully',
                'data' => [
                    'id' => $gallery->id,
                    'is_primary' => true
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk upload images
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
            'article_id' => 'nullable|exists:articles,id',
            'alt_text' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:500',
            'is_primary' => 'nullable|boolean',
            'session_key' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $image = $request->file('file');
            $articleId = $request->input('article_id');
            $sessionKey = $request->input('session_key', session()->getId());

            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

            // Create directory path
            if ($articleId) {
                $directory = "articles/{$articleId}/gallery";
            } else {
                // Temporary storage berdasarkan session
                $directory = "temp/articles/{$sessionKey}/gallery";
            }

            // Store the image
            $path = $image->storeAs($directory, $filename, 'public');
            $imagePath = storage_path('app/public/' . $path);

            // Get image dimensions
            $width = $height = null;
            try {
                $imageInfo = @getimagesize($imagePath);
                if ($imageInfo !== false) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];

                    // Optimize large images
                    if ($width > 1920 || $height > 1080) {
                        $manager = new ImageManager('gd');
                        $img = $manager->read($imagePath);
                        $img->scaleDown(width: 1920, height: 1080); // resize dengan aspect ratio otomatis
                        $img->save($imagePath, quality: 85);
                        // $img = Image::make($imagePath);
                        // $img->resize(1920, 1080, function ($constraint) {
                        //     $constraint->aspectRatio();
                        //     $constraint->upsize();
                        // });
                        // $img->save($imagePath, 85);

                        $newImageInfo = @getimagesize($imagePath);
                        if ($newImageInfo !== false) {
                            $width = $newImageInfo[0];
                            $height = $newImageInfo[1];
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to process image: ' . $e->getMessage());
            }

            // Create gallery record
            $gallery = ArticleGallery::create([
                'article_id' => $articleId, // null jika temporary
                'session_key' => $articleId ? null : $sessionKey, // simpan session key untuk temporary
                'filename' => $filename,
                'original_name' => $image->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::url($path),
                'mime_type' => $image->getMimeType(),
                'size' => $image->getSize(),
                'width' => $width,
                'height' => $height,
                'alt_text' => $request->input('alt_text'),
                'caption' => $request->input('caption'),
                'is_primary' => $request->boolean('is_primary', false),
                'uploaded_by' => auth()->id(),
                'is_temporary' => !$articleId // flag untuk temporary
            ]);

            // Handle primary image logic
            if ($gallery->is_primary && $articleId) {
                ArticleGallery::where('article_id', $articleId)
                    ->where('id', '!=', $gallery->id)
                    ->update(['is_primary' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => [
                    'id' => $gallery->id,
                    'url' => $gallery->url,
                    'name' => $gallery->original_name,
                    'size' => $gallery->size,
                    'formatted_size' => $this->formatFileSize($gallery->size),
                    'dimensions' => $width && $height ? "{$width}x{$height}" : null,
                    'alt_text' => $gallery->alt_text,
                    'caption' => $gallery->caption,
                    'is_primary' => $gallery->is_primary,
                    'is_temporary' => $gallery->is_temporary,
                    'session_key' => $sessionKey,
                    'created_at' => $gallery->created_at->toISOString()
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Image upload failed: ' . $e->getMessage());

            if (isset($path) && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bulkUpload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|max:10',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
            'article_id' => 'nullable|exists:articles,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $uploadedImages = [];
        $errors = [];

        foreach ($request->file('images') as $index => $image) {
            try {
                // Use the single upload logic
                $fakeRequest = new Request();
                $fakeRequest->files->set('image', $image);
                $fakeRequest->merge(['article_id' => $request->input('article_id')]);

                $result = $this->uploadImage($fakeRequest);
                $resultData = json_decode($result->getContent(), true);

                if ($resultData['success']) {
                    $uploadedImages[] = $resultData['data'];
                } else {
                    $errors[] = "Image " . ($index + 1) . ": " . $resultData['message'];
                }

            } catch (\Exception $e) {
                $errors[] = "Image " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        $totalUploaded = count($uploadedImages);
        $totalErrors = count($errors);

        return response()->json([
            'success' => $totalUploaded > 0,
            'message' => "Uploaded {$totalUploaded} images successfully" .
                        ($totalErrors > 0 ? " with {$totalErrors} errors" : ""),
            'data' => $uploadedImages,
            'errors' => $errors,
            'summary' => [
                'total_uploaded' => $totalUploaded,
                'total_errors' => $totalErrors
            ]
        ], $totalErrors > 0 ? 207 : 201); // 207 = Multi-Status
    }

    public function cleanupTemporary(Request $request)
    {
        $request->validate([
            'session_key' => 'required|string'
        ]);

        try {
            $sessionKey = $request->session_key;

            // Get temporary images
            $temporaryImages = ArticleGallery::where('session_key', $sessionKey)
                ->where('is_temporary', true)
                ->whereNull('article_id')
                ->get();

            $deletedCount = 0;

            foreach ($temporaryImages as $image) {
                // Delete file from storage
                if ($image->path && Storage::disk('public')->exists($image->path)) {
                    Storage::disk('public')->delete($image->path);
                }

                // Delete database record
                $image->delete();
                $deletedCount++;
            }

            // Clean up temporary directory
            $tempDir = "temp/articles/{$sessionKey}";
            if (Storage::disk('public')->exists($tempDir)) {
                Storage::disk('public')->deleteDirectory($tempDir);
            }

            return response()->json([
                'success' => true,
                'message' => "Cleaned up {$deletedCount} temporary images",
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to cleanup temporary images: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Cleanup failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cleanupExpiredTemporary()
    {
        try {
            // Delete temporary images older than 24 hours
            $expiredImages = ArticleGallery::where('is_temporary', true)
                ->whereNull('article_id')
                ->where('created_at', '<', now()->subHours(24))
                ->get();

            $deletedCount = 0;
            $deletedSessions = [];

            foreach ($expiredImages as $image) {
                // Delete file
                if ($image->path && Storage::disk('public')->exists($image->path)) {
                    Storage::disk('public')->delete($image->path);
                }

                // Track session for directory cleanup
                if ($image->session_key && !in_array($image->session_key, $deletedSessions)) {
                    $deletedSessions[] = $image->session_key;
                }

                $image->delete();
                $deletedCount++;
            }

            // Clean up temporary directories
            foreach ($deletedSessions as $sessionKey) {
                $tempDir = "temp/articles/{$sessionKey}";
                if (Storage::disk('public')->exists($tempDir)) {
                    Storage::disk('public')->deleteDirectory($tempDir);
                }
            }

            \Log::info("Cleaned up {$deletedCount} expired temporary images from " . count($deletedSessions) . " sessions");

            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'cleaned_sessions' => count($deletedSessions)
            ];

        } catch (\Exception $e) {
            \Log::error('Failed to cleanup expired temporary images: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    public function getTemporaryImages(Request $request)
    {
        $request->validate([
            'session_key' => 'required|string'
        ]);

        try {
            $images = ArticleGallery::where('session_key', $request->session_key)
                ->where('is_temporary', true)
                ->whereNull('article_id')
                // ->ordered()
                ->get()
                ->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'url' => $image->full_url,
                        'name' => $image->original_name,
                        'size' => $image->size,
                        'formatted_size' => $image->formatted_size,
                        'dimensions' => $image->dimensions,
                        'alt_text' => $image->alt_text,
                        'caption' => $image->caption,
                        'is_primary' => $image->is_primary,
                        'created_at' => $image->created_at->toISOString()
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $images,
                'count' => $images->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch temporary images: ' . $e->getMessage()
            ], 500);
        }
    }
}
