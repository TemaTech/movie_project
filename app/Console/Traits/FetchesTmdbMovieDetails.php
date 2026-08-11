<?php

namespace App\Console\Traits;

use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

trait FetchesTmdbMovieDetails
{
    private function resolveTmdbId(GlobalMovie|JapaneseMovie $movie): ?int
    {
        if (! empty($movie->tmdb_id)) {
            return (int) $movie->tmdb_id;
        }

        if (str_starts_with($movie->movie_id, 'global_')) {
            $parts = explode('_', $movie->movie_id);
            $id = end($parts);

            return is_numeric($id) ? (int) $id : null;
        }

        return null;
    }

    private function findTmdbId(GlobalMovie|JapaneseMovie $movie): ?int
    {
        $resolved = $this->resolveTmdbId($movie);
        if ($resolved) {
            return $resolved;
        }

        return $this->searchTmdbIdByTitle(
            $movie->title,
            $movie->release_date?->format('Y-m-d')
        );
    }

    private function searchTmdbIdByTitle(string $title, ?string $releaseDate): ?int
    {
        $apiKey = config('services.tmdb.api_key');
        if (empty($apiKey) || $title === '') {
            return null;
        }

        $year = $releaseDate ? substr($releaseDate, 0, 4) : null;
        if (! $year) {
            return null;
        }

        $response = Http::timeout(30)
            ->retry(2, 500)
            ->get('https://api.themoviedb.org/3/search/movie', [
                'api_key' => $apiKey,
                'query' => $title,
                'language' => 'ja-JP',
                'year' => $year,
            ]);

        usleep(100000);

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json()['results'] ?? [];
        if ($results === []) {
            return null;
        }

        $normalizedQuery = $this->normalizeTitleForSearch($title);
        $bestMatch = null;
        $highestSimilarity = -1.0;

        foreach ($results as $result) {
            $candidateTitle = $result['title'] ?? '';
            if ($candidateTitle === '') {
                continue;
            }

            $candidateYear = isset($result['release_date']) && $result['release_date']
                ? substr($result['release_date'], 0, 4)
                : null;

            if ($candidateYear && abs((int) $candidateYear - (int) $year) > 1) {
                continue;
            }

            $similarity = $this->calculateTitleSimilarity(
                $normalizedQuery,
                $this->normalizeTitleForSearch($candidateTitle)
            );

            if ($similarity < 0.6 || $similarity <= $highestSimilarity) {
                continue;
            }

            $highestSimilarity = $similarity;
            $bestMatch = $result;
        }

        return $bestMatch ? (int) $bestMatch['id'] : null;
    }

    private function normalizeTitleForSearch(string $title): string
    {
        $title = mb_strtolower($title);
        $title = mb_convert_kana($title, 'as');
        $title = str_replace(['：', '﹕', '︰'], ':', $title);
        $title = str_replace(['＆', '﹠'], '&', $title);
        $title = preg_replace('/\s+/u', ' ', $title);

        return trim((string) $title);
    }

    private function calculateTitleSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return $left === $right ? 1.0 : 0.0;
        }

        similar_text($left, $right, $percent);

        return $percent / 100;
    }

    private function fetchTmdbMovieDetail(int $tmdbId): ?array
    {
        $apiKey = config('services.tmdb.api_key');
        if (empty($apiKey)) {
            return null;
        }

        $response = Http::timeout(30)
            ->retry(2, 500)
            ->get("https://api.themoviedb.org/3/movie/{$tmdbId}", [
                'api_key' => $apiKey,
                'language' => 'ja-JP',
                'append_to_response' => 'credits',
            ]);

        usleep(100000);

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    private function slimTmdbDetail(array $detail): array
    {
        $cast = array_slice($detail['credits']['cast'] ?? [], 0, 6);
        $directors = array_values(array_filter(
            $detail['credits']['crew'] ?? [],
            fn (array $member) => ($member['job'] ?? '') === 'Director'
        ));

        return [
            'title' => $detail['title'] ?? '',
            'original_title' => $detail['original_title'] ?? '',
            'tagline' => $detail['tagline'] ?? '',
            'overview' => $detail['overview'] ?? '',
            'poster_path' => $detail['poster_path'] ?? null,
            'release_date' => $detail['release_date'] ?? null,
            'runtime' => $detail['runtime'] ?? null,
            'vote_average' => $detail['vote_average'] ?? null,
            'budget' => $detail['budget'] ?? 0,
            'revenue' => $detail['revenue'] ?? 0,
            'production_countries' => $detail['production_countries'] ?? [],
            'production_companies' => array_slice($detail['production_companies'] ?? [], 0, 3),
            'genres' => $detail['genres'] ?? [],
            'credits' => [
                'cast' => array_map(fn (array $actor) => [
                    'name' => $actor['name'] ?? '',
                    'profile_path' => $actor['profile_path'] ?? null,
                ], $cast),
                'crew' => array_map(fn (array $member) => [
                    'job' => $member['job'] ?? '',
                    'name' => $member['name'] ?? '',
                ], $directors),
            ],
        ];
    }

    private function exportMovieDetail(
        string $movieId,
        GlobalMovie|JapaneseMovie $movie,
        string $detailsDir,
        bool $refresh = false
    ): bool {
        $cachePath = storage_path("app/static-details/{$movieId}.json");

        if (! $refresh && File::exists($cachePath)) {
            File::ensureDirectoryExists($detailsDir);
            File::copy($cachePath, "{$detailsDir}/{$movieId}.json");

            return true;
        }

        $tmdbId = $this->findTmdbId($movie);
        if (! $tmdbId) {
            return false;
        }

        $detail = $this->fetchTmdbMovieDetail($tmdbId);
        if (! $detail) {
            return false;
        }

        $export = $this->slimTmdbDetail($detail);
        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        File::ensureDirectoryExists(dirname($cachePath));
        File::put($cachePath, $json);
        File::ensureDirectoryExists($detailsDir);
        File::put("{$detailsDir}/{$movieId}.json", $json);

        return true;
    }
}
