<?php

use App\Models\JapaneseMovie;
use Illuminate\Support\Facades\Http;

// Autoload
require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Verifying Japanese Movie Retrieval Logic...\n";

// 1. Get a sample Japanese Movie ID
$movie = JapaneseMovie::first();

if (!$movie) {
    echo "No Japanese movies found in DB. Cannot verify.\n";
    exit(1);
}

$id = $movie->movie_id;
echo "Testing ID: " . $id . "\n";

// 2. Simulate Controller Logic
if (str_starts_with($id, 'jp_')) {
    echo "ID starts with jp_. Proceeding with DB retrieval.\n";
    
    $retrievedMovie = JapaneseMovie::where('movie_id', $id)->first();
    
    if ($retrievedMovie) {
        echo "Movie retrieved successfully: " . $retrievedMovie->title . "\n";
        
        $movieData = $retrievedMovie->toArray();
        
        // Genre decoding simulation
        if (is_string($movieData['genres'])) {
            $movieData['genres'] = json_decode($movieData['genres'], true) ?? [];
        }
        
        echo "Genres: " . json_encode($movieData['genres'], JSON_UNESCAPED_UNICODE) . "\n";
        
        // Genre mapping simulation
        $genreMap = [
            'アニメーション' => 'アニメ',
            'サイエンスフィクション' => 'SF',
            'アニメ' => 'アニメーション',
            'SF' => 'サイエンスフィクション',
            '謎' => 'ミステリー',
            'ミステリー' => '謎',
            '犯罪' => 'サスペンス',
            'サスペンス' => '犯罪',
            '履歴' => '歴史',
            '歴史' => '履歴'
        ];
        
        if (!empty($movieData['genres'])) {
            $movieData['genres'] = array_map(function($genre) use ($genreMap) {
                return $genreMap[$genre] ?? $genre;
            }, $movieData['genres']);
        }
        
        echo "Mapped Genres: " . json_encode($movieData['genres'], JSON_UNESCAPED_UNICODE) . "\n";
        
        echo "Verification Passed!\n";
    } else {
        echo "Failed to retrieve movie from DB.\n";
    }
} else {
    echo "ID does not start with jp_. Logic mismatch.\n";
}

echo "\nVerifying Sitemap Logic...\n";
$isJapanId = isset($movie->movie_id) && is_string($movie->movie_id) && str_starts_with($movie->movie_id, 'jp_');
if ($isJapanId) {
    echo "Sitemap logic correct: ID identified as Japan ID.\n";
    echo "Sitemap URL would be: " . rtrim(config('app.url'), '/') . '/movies/' . $movie->movie_id . "\n";
} else {
    echo "Sitemap logic incorrect.\n";
}
