<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MovieController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/movies/fetch', [MovieController::class, 'fetchMovies']);
Route::get('/movies', [MovieController::class, 'index']);