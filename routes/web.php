<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovieController;
use App\Http\Controllers\SitemapController;

// メインページ（映画一覧）
Route::get('/', [MovieController::class, 'index'])->name('movies.index');
// ランディングページ（分割URL）
Route::get('/global', [MovieController::class, 'index'])->name('movies.global');
Route::get('/japan', [MovieController::class, 'index'])->name('movies.japan');
// 旧 `/movies` 直叩きの正規化（末尾スラッシュやクエリは保持）
Route::permanentRedirect('/movies', '/');

// 映画詳細ページ（TMDBの数値ID または 日本映画の文字列ID）
Route::get('/movies/{id}', [MovieController::class, 'show'])
    ->name('movies.show');

// 検索結果ページ
Route::get('/search', [MovieController::class, 'search'])->name('movies.search');

// 映画データ取得用のルートを追加
Route::get('/fetch-movies', [MovieController::class, 'fetchMovies'])->name('movies.fetch');

// API関連のルート
Route::prefix('api')->group(function () {
    Route::get('/movies/popular', [MovieController::class, 'getPopularMovies'])->name('api.movies.popular');
    Route::get('/movies/search', [MovieController::class, 'search'])->name('api.movies.search');
    Route::get('/movies/filter', [MovieController::class, 'filterByGenre'])->name('api.movies.filter');
});

Route::get('/sitemap.xml', [SitemapController::class, 'index']);