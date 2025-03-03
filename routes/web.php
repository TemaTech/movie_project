<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovieController;

Route::get('/', function () {
    return view('welcome');
});

// 映画関連のルート
Route::get('/movies', [MovieController::class, 'index'])->name('movies.index');
Route::get('/movies/fetch', [MovieController::class, 'fetchMovies'])->name('movies.fetch');