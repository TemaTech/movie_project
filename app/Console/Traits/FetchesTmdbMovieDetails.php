<?php

namespace App\Console\Traits;

use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

trait FetchesTmdbMovieDetails
{
    /**
     * Wikipedia表記とTMDb表記が大きく違う作品向けの検索用エイリアス。
     *
     * @var array<string, string>
     */
    private array $tmdbTitleAliases = [
        'M:I-2' => 'Mission: Impossible II',
        'レッドクリフPartⅡ未来への挑戦' => 'レッドクリフ Part II',
        'レッドクリフPart II未来への挑戦' => 'レッドクリフ Part II',
    ];

    /**
     * DBに公開年がなく、同名の別年版がある作品向けの公開年ヒント。
     *
     * @var array<string, int>
     */
    private array $tmdbTitleYearHints = [
        'ビルマの竪琴' => 1985, // 日本興行収入ランキング掲載は1985年リメイク
    ];

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
            $movie->release_date?->format('Y-m-d'),
            $movie->original_title
        );
    }

    private function searchTmdbIdByTitle(string $title, ?string $releaseDate, ?string $originalTitle = null): ?int
    {
        $apiKey = config('services.tmdb.api_key');
        if (empty($apiKey) || $title === '') {
            return null;
        }

        [$cleanedTitle, $yearFromTitle] = $this->extractYearFromTitle($title);
        $year = $yearFromTitle
            ?: ($releaseDate ? (int) substr($releaseDate, 0, 4) : null)
            ?: ($this->tmdbTitleYearHints[$title] ?? $this->tmdbTitleYearHints[$cleanedTitle] ?? null);

        $queries = $this->buildSearchQueries($title, $cleanedTitle, $originalTitle);
        $bestMatch = null;
        $highestSimilarity = -1.0;
        $bestPopularity = -1.0;

        foreach ($queries as $query) {
            foreach ($this->yearSearchVariants($year) as $yearFilter) {
                $results = $this->requestTmdbSearch($apiKey, $query, $yearFilter);
                foreach ($results as $result) {
                    $candidateYear = isset($result['release_date']) && $result['release_date']
                        ? (int) substr($result['release_date'], 0, 4)
                        : null;

                    if ($year && $candidateYear && abs($candidateYear - $year) > 1) {
                        continue;
                    }

                    $similarity = $this->scoreTitleMatch(
                        $cleanedTitle ?: $title,
                        $originalTitle,
                        $result,
                        $query
                    );

                    if ($similarity < 0.45) {
                        continue;
                    }

                    $popularity = (float) ($result['popularity'] ?? 0);
                    $isBetter = $similarity > $highestSimilarity + 0.001
                        || (
                            $similarity >= 0.9
                            && abs($similarity - $highestSimilarity) < 0.001
                            && $popularity > $bestPopularity
                        );

                    if (! $isBetter) {
                        continue;
                    }

                    $highestSimilarity = $similarity;
                    $bestPopularity = $popularity;
                    $bestMatch = $result;
                }

                if ($highestSimilarity >= 0.9 && $year) {
                    break 2;
                }
            }
        }

        return $bestMatch ? (int) $bestMatch['id'] : null;
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    private function extractYearFromTitle(string $title): array
    {
        $year = null;
        $cleaned = $title;

        if (preg_match('/[（(]\s*((?:19|20)\d{2})\s*年?\s*[）)]/u', $title, $matches)) {
            $year = (int) $matches[1];
            $cleaned = trim(preg_replace('/[（(]\s*((?:19|20)\d{2})\s*年?\s*[）)]/u', '', $title) ?? $title);
        }

        return [$cleaned, $year];
    }

    /**
     * @return list<string>
     */
    private function buildSearchQueries(string $title, string $cleanedTitle, ?string $originalTitle): array
    {
        $queries = [];

        $push = function (string $query) use (&$queries): void {
            $query = trim($query);
            if ($query !== '' && ! in_array($query, $queries, true)) {
                $queries[] = $query;
            }
        };

        $push($title);
        $push($cleanedTitle);

        if (isset($this->tmdbTitleAliases[$title])) {
            $push($this->tmdbTitleAliases[$title]);
        }
        if (isset($this->tmdbTitleAliases[$cleanedTitle])) {
            $push($this->tmdbTitleAliases[$cleanedTitle]);
        }

        if ($originalTitle) {
            $push($originalTitle);
        }

        // 「劇場版」接頭辞を外した候補
        $withoutTheatrical = preg_replace('/^劇場版\s*/u', '', $cleanedTitle) ?? $cleanedTitle;
        $push($withoutTheatrical);

        // 日本語タイトル + 英語サブタイトル（例: デスノート the Last name）→ 日本語部分
        // 先頭が英字の作品（例: BRAVE HEARTS 海猿）は分割しない
        if (preg_match('/^(.+?)\s+([A-Za-z].+)$/u', $cleanedTitle, $matches)) {
            $leadingPart = $matches[1];
            if (! preg_match('/^[A-Za-z0-9]/', $leadingPart)) {
                $push($leadingPart);
            }
        }

        // PartⅡ / Part2 表記ゆれ
        $partNormalized = str_replace(
            ['PartⅡ', 'Part II', 'PARTⅡ', 'Ⅱ', 'Ⅱ'],
            [' Part II', ' Part II', ' Part II', ' II', ' II'],
            $cleanedTitle
        );
        $push(preg_replace('/\s+/u', ' ', $partNormalized) ?? $partNormalized);

        return $queries;
    }

    /**
     * @return list<?int>
     */
    private function yearSearchVariants(?int $year): array
    {
        if (! $year) {
            return [null];
        }

        return [$year, $year - 1, $year + 1, null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestTmdbSearch(string $apiKey, string $query, ?int $year): array
    {
        $params = [
            'api_key' => $apiKey,
            'query' => $query,
            'language' => 'ja-JP',
        ];

        if ($year) {
            $params['primary_release_year'] = $year;
        }

        $response = Http::timeout(30)
            ->retry(2, 500)
            ->get('https://api.themoviedb.org/3/search/movie', $params);

        usleep(100000);

        if (! $response->successful()) {
            return [];
        }

        return $response->json()['results'] ?? [];
    }

    private function scoreTitleMatch(string $title, ?string $originalTitle, array $result, ?string $searchQuery = null): float
    {
        $candidates = array_filter([
            $result['title'] ?? null,
            $result['original_title'] ?? null,
        ]);

        // エイリアス検索時は検索クエリ自体も照合対象にする
        $inputs = array_filter([$title, $originalTitle, $searchQuery]);
        $best = 0.0;

        foreach ($inputs as $input) {
            $normalizedInput = $this->normalizeTitleForSearch($input);
            foreach ($candidates as $candidate) {
                $normalizedCandidate = $this->normalizeTitleForSearch($candidate);
                $best = max($best, $this->calculateTitleSimilarity($normalizedInput, $normalizedCandidate));

                // 短い単語の部分一致（Brave ⊂ BRAVE HEARTS）による誤爆を避ける
                $shorter = mb_strlen($normalizedInput) <= mb_strlen($normalizedCandidate)
                    ? $normalizedInput
                    : $normalizedCandidate;
                $longer = $shorter === $normalizedInput ? $normalizedCandidate : $normalizedInput;
                if (mb_strlen($shorter) >= 6 && str_starts_with($longer, $shorter)) {
                    $best = max($best, 0.88);
                }
            }
        }

        return $best;
    }

    private function normalizeTitleForSearch(string $title): string
    {
        $title = mb_strtolower($title);
        $title = mb_convert_kana($title, 'as');
        $title = str_replace(['：', '﹕', '︰'], ':', $title);
        $title = str_replace(['＆', '﹠'], '&', $title);
        $title = str_replace(['Ⅱ', 'ii'], '2', $title);
        $title = preg_replace('/[「」『』【】\[\]（）()・\/／\-–—_]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title);

        return trim((string) $title);
    }

    private function calculateTitleSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return $left === $right ? 1.0 : 0.0;
        }

        if ($left === $right) {
            return 1.0;
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
