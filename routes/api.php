<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    ArticleController,
    CategoryController,
    AuthController,
    ArticleGalleryController
};
use App\Http\Controllers\Api\Admin\{
    DashboardController,
    ArticleController as AdminArticleController,
    CategoryController as AdminCategoryController,
    UserController as AdminUserController,
    ArticleGalleryController as AdminArticleGalleryController
};

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// API v1
Route::prefix('v1')->group(function () {

    /** -----------------------
     *  Public Routes
     *  -----------------------
     */
    // Articles
    Route::get('articles', [ArticleController::class, 'index']);
    Route::get('articles/{slug}', [ArticleController::class, 'show']);

    Route::get('articles/{article}/download', [ArticleController::class, 'downloadAttachment']);

    // Categories
    Route::get('categories', [CategoryController::class, 'index']);

    // Authentication
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);

    Route::get('articles/{articleId}/gallery', [ArticleGalleryController::class, 'getArticleImages']);

    /** -----------------------
     *  Protected Routes
     *  -----------------------
     */
    Route::middleware('auth:sanctum')->group(function () {

        // User info
        Route::get('user', function (Request $request) {
            return response()->json([
                'status' => 'success',
                'data'   => $request->user(),
            ]);
        });

        // Logout
        Route::post('logout', [AuthController::class, 'logout']);

        // Article interactions
        Route::post('articles/{article}/rate', [ArticleController::class, 'rate']);
        Route::post('articles/gallery/upload', [ArticleController::class, 'uploadGallery']);
    });

    /** -----------------------
     *  Admin Routes
     *  -----------------------
     */
    Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('articles/{articleId}/gallery', [ArticleGalleryController::class, 'getArticleImages']);
        Route::get('articles/{article}/download', [ArticleController::class, 'downloadAttachment']);

        // CRUD Management
        Route::apiResource('articles', AdminArticleController::class);
        Route::post('articles/link-images', [ArticleController::class, 'linkImages']);
        Route::apiResource('categories', AdminCategoryController::class);
        Route::apiResource('users', AdminUserController::class);

        // Article gallery management
        Route::prefix('articles')->group(function () {
            Route::post('link-images', [ArticleController::class, 'uploadImage']);
            Route::post('gallery/link-temporary', [AdminArticleGalleryController::class, 'linkTemporaryImages']);
            Route::post('upload-image', [AdminArticleGalleryController::class, 'uploadImage']);
            Route::post('bulk-upload-images', [AdminArticleGalleryController::class, 'bulkUpload']);

            Route::get('{articleId}/images', [AdminArticleGalleryController::class, 'getArticleImages']);
            Route::put('{articleId}/images/reorder', [AdminArticleGalleryController::class, 'reorderImages']);

            Route::prefix('images')->group(function () {
                Route::put('{imageId}', [AdminArticleGalleryController::class, 'updateImage']);
                Route::delete('{imageId}', [AdminArticleGalleryController::class, 'deleteImage']);
                Route::put('{imageId}/set-primary', [AdminArticleGalleryController::class, 'setPrimaryImage']);
            });
        });
    });
});
