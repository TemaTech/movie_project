<?php

namespace App\Http\Controllers;

use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class SitemapController extends Controller
{
    public function index()
    {
        try {
            $globalMovies = GlobalMovie::all();
            $japaneseMovies = JapaneseMovie::all();
            
            $movies = $globalMovies->concat($japaneseMovies);
            
            $content = view('sitemap', [
                'movies' => $movies
            ])->render();

            return Response::make($content, 200, [
                'Content-Type' => 'application/xml',
                'Cache-Control' => 'public, max-age=3600'
            ]);
        } catch (\Exception $e) {
            Log::error('Sitemap generation failed', [
                'error' => $e->getMessage()
            ]);
            abort(500, 'サイトマップの生成に失敗しました');
        }
    }
} 