<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedPostController;
use Illuminate\Support\Facades\Route;

// Authentication Routes (/api/auth/* & aliases)
Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/google', [AuthController::class, 'googleAuth']);
    Route::get('/google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [ProfileController::class, 'show']);
        Route::match(['put', 'patch', 'post'], '/profile', [ProfileController::class, 'update']);
    });
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/google', [AuthController::class, 'googleAuth']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile Routes
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::match(['put', 'patch', 'post'], '/profile', [ProfileController::class, 'update']);

    // Authenticated Post Routes (placed before /posts/{post})
    Route::get('/posts/me', [PostController::class, 'myPosts']);
    Route::get('/posts/saved', [SavedPostController::class, 'index']);
    Route::post('/posts', [PostController::class, 'store']);
    Route::match(['put', 'patch'], '/posts/{post}', [PostController::class, 'update'])->whereNumber('post');
    Route::patch('/posts/{post}/status', [PostController::class, 'update'])->whereNumber('post');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');

    // Saved Posts / Bookmark Routes
    Route::post('/posts/{post}/bookmark', [SavedPostController::class, 'toggle'])->whereNumber('post');
    Route::get('/saved-posts', [SavedPostController::class, 'index']);
    Route::post('/saved-posts/toggle', [SavedPostController::class, 'toggle']);
    Route::post('/saved-posts/{post}/toggle', [SavedPostController::class, 'toggle'])->whereNumber('post');

    // Authenticated Comment Routes
    Route::post('/posts/{post}/comments', [CommentController::class, 'store'])->whereNumber('post');
    Route::post('/comments', [CommentController::class, 'store']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('comment');

    // Notification Routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->whereNumber('notification');
});

// Public Post & Comment Routes
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
Route::get('/posts/{post}/comments', [CommentController::class, 'index'])->whereNumber('post');
