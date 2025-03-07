<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\Response;

class SitemapController extends Controller
{
    public function index()
    {
        $content = view('sitemap', [
            'movies' => Movie::all()
        ])->render();

        return Response::make($content, 200, [
            'Content-Type' => 'application/xml'
        ]);
    }
} 