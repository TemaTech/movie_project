<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class SitemapController extends Controller
{
    public function index()
    {
        try {
            Log::info('Sitemap generation started');
            
            $movies = Movie::all();
            Log::info('Movies fetched', ['count' => $movies->count()]);
            
            $content = view('sitemap', [
                'movies' => $movies
            ])->render();
            
            Log::info('Sitemap generated successfully', [
                'content_length' => strlen($content)
            ]);

            return Response::make($content, 200, [
                'Content-Type' => 'application/xml',
                'X-Robots-Tag' => 'noindex',
                'Cache-Control' => 'public, max-age=3600'
            ]);

        } catch (\Exception $e) {
            Log::error('Sitemap generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            abort(500, 'サイトマップの生成に失敗しました');
        }
    }
} 