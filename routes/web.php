<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovieController;

// メインページ（映画一覧）
Route::get('/', [MovieController::class, 'index'])->name('movies.index');

// 映画詳細ページ
Route::get('/movies/{id}', [MovieController::class, 'show'])->name('movies.show');

// 検索結果ページ
Route::get('/search', [MovieController::class, 'search'])->name('movies.search');

// API関連のルート
Route::prefix('api')->group(function () {
    Route::get('/movies/popular', [MovieController::class, 'getPopular'])->name('api.movies.popular');
    Route::get('/movies/search', [MovieController::class, 'searchApi'])->name('api.movies.search');
});