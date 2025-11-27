<?php

use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;

// Autoload
require __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Global Movies Sample IDs:\n";
$globalMovies = GlobalMovie::take(5)->get();
foreach ($globalMovies as $movie) {
    echo $movie->movie_id . "\n";
}

echo "\nJapanese Movies Sample IDs:\n";
$japaneseMovies = JapaneseMovie::take(5)->get();
foreach ($japaneseMovies as $movie) {
    echo $movie->movie_id . "\n";
}

echo "\nChecking for IDs with underscores:\n";
$underscores = JapaneseMovie::where('movie_id', 'LIKE', '%_%')->take(5)->get();
foreach ($underscores as $movie) {
    echo "Found Japanese Movie with underscore: " . $movie->movie_id . "\n";
}
