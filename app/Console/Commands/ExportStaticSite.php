<?php

namespace App\Console\Commands;

use App\Console\Traits\FetchesTmdbMovieDetails;
use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use App\Services\BoxOffice\Contracts\RegistryRepository;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\BoxOffice\Insights;
use App\Services\BoxOffice\MovieIdentity;
use App\Services\BoxOffice\TheatricalRun;
use App\Services\News\BoxOfficeNewsMatcher;
use App\Services\News\YahooBoxOfficeNews;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ExportStaticSite extends Command
{
    use FetchesTmdbMovieDetails;

    protected $signature = 'site:export-static
                            {--output=dist : Export directory}
                            {--refresh-details : Re-fetch all TMDb detail files}
                            {--skip-news : Do not fetch box office news links}';

    protected $description = 'Export the movie rankings as a static site for Cloudflare Pages';

    /** @var array<string, list<array{key: string, title: string, points: list<array{at: string, boxOffice: int}>}>> */
    private array $activeSeries = ['japan' => [], 'global' => []];

    /** JS の PER_PAGE（resources/js/static-site.js）と揃える */
    private const RANKING_PAGE_SIZE = 100;

    /** @var list<string> */
    private const COMPARE_COLORS = [
        '#7dd3fc',
        '#c4b5fd',
        '#34d399',
        '#fb7185',
        '#fbbf24',
        '#22d3ee',
        '#f472b6',
        '#a78bfa',
    ];

    public function handle(): int
    {
        $output = base_path($this->option('output'));
        $refreshDetails = (bool) $this->option('refresh-details');

        File::deleteDirectory($output);
        File::ensureDirectoryExists($output . '/data/details');

        $globalModels = GlobalMovie::orderBy('box_office', 'desc')->get();
        $japanModels = JapaneseMovie::orderBy('box_office', 'desc')->get();

        $this->exportMovieDetails($globalModels, $output . '/data/details', $refreshDetails);
        $this->exportMovieDetails($japanModels, $output . '/data/details', $refreshDetails);

        $global = $globalModels->values()
            ->map(fn (GlobalMovie $movie, int $index) => $this->movieData($movie, $index + 1, false))
            ->all();
        $japan = $japanModels->values()
            ->map(fn (JapaneseMovie $movie, int $index) => $this->movieData($movie, $index + 1, true))
            ->all();

        $history = HistoryRecorder::fromConfig();
        [$global, $globalInsights] = $this->attachInsights('global', $global, $history);
        [$japan, $japanInsights] = $this->attachInsights('japan', $japan, $history);

        $this->activeSeries = [
            'global' => $this->buildActiveSeries($globalInsights, false),
            'japan' => $this->buildActiveSeries($japanInsights, true),
        ];

        if (! $this->option('skip-news')) {
            try {
                $this->attachBoxOfficeNews($global, $japan, $globalInsights, $japanInsights);
            } catch (\Throwable $exception) {
                Log::warning('Box office news was skipped', ['error' => $exception->getMessage()]);
                $this->warn('興行収入ニュースの取得に失敗したため、Newsボタンなしで書き出します: '.$exception->getMessage());
            }
        }

        $globalLastUpdated = $this->maxLastUpdated(GlobalMovie::class);
        $japanLastUpdated = $this->maxLastUpdated(JapaneseMovie::class);

        File::put($output . '/data/movies.json', json_encode([
            'generatedAt' => now('Asia/Tokyo')->toIso8601String(),
            'usdJpy' => $this->usdJpy(),
            'globalLastUpdated' => $globalLastUpdated,
            'japanLastUpdated' => $japanLastUpdated,
            'global' => $this->moviesForClientPayload($global),
            'japan' => $this->moviesForClientPayload($japan),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->exportNowPlaying($output, $global, $japan, $globalInsights, $japanInsights);
        $this->exportRedirects($output, $history);
        $this->exportLegacyMap($output, $history);
        $this->exportFeed($output, $globalInsights, $japanInsights);

        $this->copyPublicAssets($output);
        $this->exportRobotsTxt($output);
        $boardOnly = $this->boardOnlyMovies($globalInsights, $japanInsights, $global, $japan);
        $this->exportMoviePages($output, collect($global), collect($japan));
        foreach ($boardOnly as $movie) {
            $this->writeMoviePage($output, $movie, ($movie['region'] ?? '') === 'japan');
        }
        $this->exportTrustPages($output);
        $this->exportNowPage($output);
        $this->exportJapanRankingPage($output, $japan, $japanLastUpdated);
        $this->exportSitemap($output, $global, $japan, $boardOnly);
        File::put($output . '/index.html', $this->indexHtml('/', $global, $globalLastUpdated));

        $moviePageCount = count($global) + count($japan) + count($boardOnly);
        $this->info("Static site exported to {$output}");
        $this->line("Global: ".count($global)." movies / Japan: ".count($japan)." movies");
        $this->line("Movie pages: {$moviePageCount}");

        return self::SUCCESS;
    }

    /**
     * 公開中の作品だけを対象に、Yahoo!ニュース上の興行収入記事を付ける。
     * 記事が無い作品には news を付けない。
     *
     * @param  list<array<string, mixed>>  $global
     * @param  list<array<string, mixed>>  $japan
     * @param  array<string, mixed>  $globalInsights
     * @param  array<string, mixed>  $japanInsights
     */
    private function attachBoxOfficeNews(array &$global, array &$japan, array &$globalInsights, array &$japanInsights): void
    {
        $targets = [];
        foreach ([$japanInsights, $globalInsights] as $insights) {
            foreach ($insights['board'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->pushNewsTarget($targets, (string) ($item['key'] ?? ''), (string) ($item['title'] ?? ''), null);
            }
        }
        foreach ([$japan, $global] as $movies) {
            foreach ($movies as $movie) {
                if (empty($movie['isActive'])) {
                    continue;
                }
                $this->pushNewsTarget(
                    $targets,
                    (string) ($movie['id'] ?? ''),
                    (string) ($movie['title'] ?? ''),
                    isset($movie['originalTitle']) ? (string) $movie['originalTitle'] : null,
                );
            }
        }

        $targets = array_slice($targets, 0, 40, true);
        if ($targets === []) {
            $this->line('興行収入ニュース: 対象の公開中作品はありません');

            return;
        }

        $found = (new YahooBoxOfficeNews(cachePath: storage_path('app/news-cache.json')))
            ->forMovies(array_values($targets));
        $byTitle = [];
        foreach ($targets as $target) {
            $items = $found[$target['key']] ?? [];
            if ($items === []) {
                continue;
            }
            $byTitle[BoxOfficeNewsMatcher::normalize($target['title'])] = $items;
        }

        $this->stampNews($global, $found, $byTitle, 'id');
        $this->stampNews($japan, $found, $byTitle, 'id');
        if (isset($globalInsights['board']) && is_array($globalInsights['board'])) {
            $this->stampNews($globalInsights['board'], $found, $byTitle, 'key');
        }
        if (isset($japanInsights['board']) && is_array($japanInsights['board'])) {
            $this->stampNews($japanInsights['board'], $found, $byTitle, 'key');
        }

        $placements = count(array_filter($global, fn (array $movie) => ! empty($movie['news'])))
            + count(array_filter($japan, fn (array $movie) => ! empty($movie['news'])));
        $this->info('興行収入ニュース: ランキング '.$placements.' 作品にリンクを付けました');
    }

    /**
     * @param  array<string, array{key: string, title: string, originalTitle: ?string}>  $targets
     */
    private function pushNewsTarget(array &$targets, string $key, string $title, ?string $originalTitle): void
    {
        $key = trim($key);
        $title = trim($title);
        if ($key === '' || $title === '' || isset($targets[$key])) {
            return;
        }
        if (count($targets) >= 40) {
            return;
        }
        $targets[$key] = [
            'key' => $key,
            'title' => $title,
            'originalTitle' => $originalTitle !== null && trim($originalTitle) !== '' ? trim($originalTitle) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $found
     * @param  array<string, list<array<string, mixed>>>  $byTitle
     */
    private function stampNews(array &$rows, array $found, array $byTitle, string $keyField): void
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row[$keyField] ?? '');
            $titleKey = BoxOfficeNewsMatcher::normalize((string) ($row['title'] ?? ''));
            $items = $found[$key] ?? $byTitle[$titleKey] ?? [];
            if ($items !== []) {
                $rows[$index]['news'] = $items;
            }
        }
    }

    private function exportMovieDetails($movies, string $detailsDir, bool $refresh): void
    {
        $exported = 0;
        $skipped = 0;

        foreach ($movies as $movie) {
            if ($this->exportMovieDetail($movie->movie_id, $movie, $detailsDir, $refresh)) {
                $exported++;
            } else {
                $skipped++;
            }
        }

        $this->line("Details exported: {$exported} / skipped: {$skipped}");
    }

    private function maxLastUpdated(string $modelClass): ?string
    {
        $value = $modelClass::whereNotNull('last_updated')->max('last_updated');

        return $value
            ? Carbon::parse($value)->timezone('Asia/Tokyo')->format('Y-m-d H:i:s')
            : null;
    }

    private function genreMap(): array
    {
        return [
            'アニメーション' => 'アニメ',
            'サイエンスフィクション' => 'SF',
            'アニメ' => 'アニメーション',
            'SF' => 'サイエンスフィクション',
            '謎' => 'ミステリー',
            'ミステリー' => '謎',
            '犯罪' => 'サスペンス',
            'サスペンス' => '犯罪',
            '履歴' => '歴史',
            '歴史' => '履歴',
        ];
    }

    private function titleMap(): array
    {
        return [
            '哪吒之魔童闘海' => 'ナタ 魔童の大暴れ',
            '哪吒之魔童闹海' => 'ナタ 魔童の大暴れ',
        ];
    }

    private function movieData(GlobalMovie|JapaneseMovie $movie, int $rank, bool $isJapan): array
    {
        $releaseDate = $movie->release_date?->format('Y-m-d');
        $poster = $this->resolvePosterPath($movie);

        $rawGenres = is_array($movie->genres) ? $movie->genres : (json_decode((string) $movie->genres, true) ?: []);
        $genres = array_values(array_map(
            fn (string $genre) => $this->genreMap()[$genre] ?? $genre,
            $rawGenres,
        ));
        $isActive = (bool) $movie->is_active;
        if (! $isJapan && ! $movie->is_active && $releaseDate) {
            $isActive = Carbon::parse($releaseDate)->greaterThanOrEqualTo(now('Asia/Tokyo')->subMonths(6));
        }

        $usdJpy = $this->usdJpy();
        $revenueBillion = $isJapan
            ? number_format($movie->box_office / 100000000, 1)
            : number_format($movie->box_office * $usdJpy / 100000000, 1);

        $dbTitle = $movie->title;
        $title = $this->titleMap()[$dbTitle] ?? $dbTitle;

        return [
            'id' => $movie->movie_id,
            'slug' => $movie->movie_id,
            'tmdbId' => $movie->tmdb_id,
            'rank' => $rank,
            'title' => $title,
            'originalTitle' => $movie->original_title,
            'releaseDate' => $releaseDate,
            'releaseYear' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
            'releaseDatePrecision' => $movie->release_date_precision ?? null,
            'genres' => array_values($genres),
            'posterUrl' => $poster,
            'isActive' => $isActive,
            'isAnime' => in_array('アニメ', $rawGenres, true) || in_array('アニメーション', $rawGenres, true),
            'boxOffice' => (int) $movie->box_office,
            'revenueBillion' => $revenueBillion,
            'productionCountry' => $isJapan ? ($movie->production_country ?? '日本') : null,
            'revenue' => $isJapan
                ? number_format($movie->box_office / 100000000, 1) . '億円'
                : number_format($movie->box_office / 100000000, 2) . '億ドル',
            'revenueYen' => $isJapan ? null : number_format($movie->box_office * $usdJpy / 100000000, 1) . '億円',
            'analysis' => $movie->ai_analysis,
            'sourceUrl' => $movie->data_source_url,
            'updatedAt' => $movie->last_updated
                ? Carbon::parse($movie->last_updated)->timezone('Asia/Tokyo')->toAtomString()
                : null,
        ];
    }

    private function resolvePosterPath(GlobalMovie|JapaneseMovie $movie): ?string
    {
        $poster = $movie->poster_path;

        if (empty($poster)) {
            $cachePath = storage_path("app/static-details/{$movie->movie_id}.json");
            if (File::exists($cachePath)) {
                $detail = json_decode(File::get($cachePath), true);
                $poster = is_array($detail) ? ($detail['poster_path'] ?? null) : null;
            }
        }

        if (empty($poster)) {
            return null;
        }

        if (str_starts_with($poster, 'posters/')) {
            return '/storage/' . $poster;
        }

        if (str_starts_with($poster, 'http')) {
            return $poster;
        }

        return 'https://image.tmdb.org/t/p/w342' . $poster;
    }

    private function copyPublicAssets(string $output): void
    {
        foreach (['build', 'images'] as $directory) {
            $source = public_path($directory);
            if (File::isDirectory($source)) {
                File::copyDirectory($source, $output . '/' . $directory);
            }
        }

        foreach (['sw.js', 'favicon.ico', 'site.webmanifest', 'google84b5e42071a0a6f0.html'] as $file) {
            $source = public_path($file);
            if (File::exists($source)) {
                File::copy($source, $output . '/' . $file);
            }
        }

        $storage = storage_path('app/public');
        if (File::isDirectory($storage)) {
            File::copyDirectory($storage, $output . '/storage');
        }
    }

    private function robotsTxt(): string
    {
        $baseUrl = rtrim(config('app.url'), '/');

        return <<<TXT
User-agent: *
Allow: /data/movies.json
Allow: /data/now-playing.json
Allow: /

# サイトマップ
Sitemap: {$baseUrl}/sitemap.xml

# 検索エンジンにクロールしてほしくないディレクトリ
Disallow: /storage/
Disallow: /data/
TXT;
    }

    private function exportRobotsTxt(string $output): void
    {
        File::put($output . '/robots.txt', $this->robotsTxt());
    }

    private function usdJpy(): float
    {
        $rate = (float) config('box_office.usd_jpy', 150);

        return $rate > 0 ? $rate : 150.0;
    }

    private function usdJpyLabel(): string
    {
        return (string) (int) round($this->usdJpy());
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @return list<array<string, mixed>>
     */
    private function moviesForClientPayload(array $movies): array
    {
        return array_map(function (array $movie) {
            unset($movie['pageHistory']);

            return $movie;
        }, $movies);
    }

    private function movieSlug(string $movieId): string
    {
        return $movieId;
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function attachInsights(string $region, array $movies, HistoryRecorder $history): array
    {
        $registry = $history->registry();
        $observationsByKey = $history->observations()->loadByKey($region);
        $registryMovies = $registry->movies();
        $now = now('Asia/Tokyo')->toDateTimeImmutable();
        if ($region === 'global') {
            [$movies, $registryMovies] = $this->reconcileGlobalRuns(
                $movies,
                $registry,
                $registryMovies,
                $observationsByKey,
                $now,
            );
        }

        $current = array_map(function (array $movie) use ($registry) {
            $canonical = $registry->resolveCanonicalKey((string) $movie['id']);

            return [
                'key' => $canonical,
                'title' => $movie['title'],
                'boxOffice' => $movie['boxOffice'],
                'isActive' => $movie['isActive'],
                'rank' => $movie['rank'],
                'releaseDate' => $movie['releaseDate'],
                'releaseDatePrecision' => $movie['releaseDatePrecision'] ?? null,
                'posterUrl' => $movie['posterUrl'] ?? null,
                'revenue' => $movie['revenue'] ?? null,
            ];
        }, $movies);

        $computed = Insights::compute(
            $region,
            $current,
            $observationsByKey,
            $registryMovies,
            $now,
        );

        foreach ($movies as $index => $movie) {
            $canonical = $registry->resolveCanonicalKey((string) $movie['id']);
            $insight = $computed['movies'][$canonical] ?? $computed['movies'][$movie['id']] ?? null;
            if (! $insight) {
                continue;
            }
            $movies[$index]['momentum'] = [
                'delta' => $insight['delta'],
                'deltaLabel' => $insight['deltaLabel'],
                'daysSincePrev' => $insight['daysSincePrev'],
                'dailyPaceLabel' => $insight['dailyPaceLabel'],
                'rankDelta' => $insight['rankDelta'],
                'rankDeltaLabel' => $insight['rankDeltaLabel'],
                'passedLabel' => $insight['passedLabel'],
                'hasHistory' => $insight['hasHistory'],
                'daysSinceRelease' => $insight['daysSinceRelease'],
            ];
            $movies[$index]['pageHistory'] = [
                'seriesKey' => $canonical,
                'sparkline' => $insight['sparkline'] ?? [],
                'milestones' => $insight['milestones'] ?? [],
                'nextMilestone' => $insight['nextMilestone'] ?? null,
                'nextToOvertake' => $insight['nextToOvertake'] ?? null,
                'periodGrowth' => $insight['periodGrowth'] ?? [],
                'releaseDate' => $insight['currentReleaseDate'] ?? $insight['releaseDate'] ?? ($movie['releaseDate'] ?? null),
                'releaseDatePrecision' => ! empty($insight['currentReleaseDate'])
                    ? 'day'
                    : ($insight['releaseDatePrecision'] ?? ($movie['releaseDatePrecision'] ?? null)),
            ];
        }

        foreach (['board', 'today', 'milestones'] as $bucket) {
            $computed[$bucket] = array_map(function (array $item) use ($movies) {
                $movie = collect($movies)->firstWhere('id', $item['key']) ?? [];
                return array_merge($item, [
                    'posterUrl' => $movie['posterUrl'] ?? null,
                    'slug' => $item['key'],
                    'revenue' => $movie['revenue'] ?? ($item['revenueLabel'] ?? null),
                ]);
            }, $computed[$bucket]);
        }

        return [$movies, $computed];
    }

    /**
     * 過去作品は、リバイバル開始後に累計興収が増えているときだけ公開中のままにする。
     *
     * @param  list<array<string, mixed>>  $movies
     * @param  array<string, array<string, mixed>>  $registryMovies
     * @param  array<string, list<array<string, mixed>>>  $observationsByKey
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function reconcileGlobalRuns(
        array $movies,
        RegistryRepository $registry,
        array $registryMovies,
        array $observationsByKey,
        \DateTimeImmutable $now,
    ): array {
        $boxOfficeByKey = [];
        foreach ($movies as $index => $movie) {
            $canonical = $registry->resolveCanonicalKey((string) $movie['id']);
            $boxOfficeByKey[$canonical] = (int) ($movie['boxOffice'] ?? 0);
            if (empty($movie['isActive'])) {
                continue;
            }
            $registryMovie = $registryMovies[$canonical] ?? [];
            $primary = $registryMovie['releaseDate'] ?? ($movie['releaseDate'] ?? null);
            $revival = $registryMovie['currentReleaseDate'] ?? null;
            $keep = TheatricalRun::storedRunIsCurrent(
                is_string($primary) ? $primary : null,
                is_string($revival) ? $revival : null,
                $observationsByKey[$canonical] ?? [],
                (int) ($movie['boxOffice'] ?? 0),
                $now,
            );
            if (! $keep) {
                $movies[$index]['isActive'] = false;
            }
        }

        foreach ($registryMovies as $key => $registryMovie) {
            if (empty($registryMovie['currentReleaseDate']) || ! is_string($registryMovie['currentReleaseDate'])) {
                continue;
            }
            $boxOffice = $boxOfficeByKey[$key] ?? null;
            if ($boxOffice === null) {
                $rows = $observationsByKey[$key] ?? [];
                $last = $rows === [] ? null : $rows[array_key_last($rows)];
                $boxOffice = (int) ($last['boxOffice'] ?? 0);
            }
            $primary = $registryMovie['releaseDate'] ?? null;
            $confirmed = TheatricalRun::confirmedRevivalDate(
                is_string($primary) ? $primary : null,
                $registryMovie['currentReleaseDate'],
                $observationsByKey[$key] ?? [],
                $boxOffice,
                $now,
            );
            if ($confirmed === null) {
                unset($registryMovies[$key]['currentReleaseDate']);
            }
        }

        return [$movies, $registryMovies];
    }

    /**
     * @param  array<string, mixed>  $globalInsights
     * @param  array<string, mixed>  $japanInsights
     * @param  list<array<string, mixed>>  $global
     * @param  list<array<string, mixed>>  $japan
     * @return list<array<string, mixed>>
     */
    private function boardOnlyMovies(array $globalInsights, array $japanInsights, array $global, array $japan): array
    {
        $known = [];
        foreach (array_merge($global, $japan) as $movie) {
            $known[$movie['id']] = true;
        }

        $extra = [];
        foreach (['global' => $globalInsights['board'] ?? [], 'japan' => $japanInsights['board'] ?? []] as $region => $board) {
            foreach ($board as $item) {
                $key = $item['key'] ?? null;
                if (! $key || isset($known[$key])) {
                    continue;
                }
                $known[$key] = true;
                $isJapan = $region === 'japan';
                $extra[] = [
                    'id' => $key,
                    'slug' => $key,
                    'title' => $item['title'] ?? $key,
                    'originalTitle' => null,
                    'rank' => $item['rank'] ?: '圏外',
                    'releaseDate' => $item['releaseDate'] ?? null,
                    'releaseDatePrecision' => $item['releaseDatePrecision'] ?? null,
                    'genres' => [],
                    'posterUrl' => $item['posterUrl'] ?? null,
                    'boxOffice' => $item['boxOffice'] ?? 0,
                    'revenue' => $item['revenueLabel'] ?? ($item['revenue'] ?? ''),
                    'revenueYen' => null,
                    'region' => $region,
                    'isActive' => true,
                    'momentum' => [
                        'delta' => $item['delta'] ?? null,
                        'deltaLabel' => $item['deltaLabel'] ?? null,
                        'daysSincePrev' => $item['daysSincePrev'] ?? null,
                        'dailyPaceLabel' => $item['dailyPaceLabel'] ?? null,
                        'rankDelta' => $item['rankDelta'] ?? null,
                        'rankDeltaLabel' => $item['rankDeltaLabel'] ?? null,
                        'passedLabel' => $item['passedLabel'] ?? null,
                        'hasHistory' => $item['hasHistory'] ?? false,
                        'daysSinceRelease' => $item['daysSinceRelease'] ?? null,
                    ],
                    'pageHistory' => [
                        'seriesKey' => $key,
                        'sparkline' => $item['sparkline'] ?? [],
                        'milestones' => $item['milestones'] ?? [],
                        'nextMilestone' => $item['nextMilestone'] ?? null,
                        'nextToOvertake' => $item['nextToOvertake'] ?? null,
                        'periodGrowth' => $item['periodGrowth'] ?? [],
                        'releaseDate' => $item['currentReleaseDate'] ?? $item['releaseDate'] ?? null,
                        'releaseDatePrecision' => ! empty($item['currentReleaseDate'])
                            ? 'day'
                            : ($item['releaseDatePrecision'] ?? null),
                    ],
                ];
            }
        }

        return $extra;
    }

    private function exportNowPlaying(string $output, array $global, array $japan, array $globalInsights, array $japanInsights): void
    {
        File::put($output . '/data/now-playing.json', json_encode([
            'generatedAt' => now('Asia/Tokyo')->toIso8601String(),
            'disclaimer' => '日本の興行収入は配給会社の発表ベースです。数字は毎日は動きません。伸びは前回の発表との差です。',
            'japanLastUpdated' => $this->maxLastUpdated(JapaneseMovie::class),
            'globalLastUpdated' => $this->maxLastUpdated(GlobalMovie::class),
            'japan' => [
                'board' => $japanInsights['board'],
                'today' => $japanInsights['today'],
                'milestones' => $japanInsights['milestones'],
            ],
            'global' => [
                'board' => $globalInsights['board'],
                'today' => $globalInsights['today'],
                'milestones' => $globalInsights['milestones'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function exportRedirects(string $output, HistoryRecorder $history): void
    {
        $lines = [
            '/now /now/ 301',
            '/now/global /now/global/ 301',
        ];
        foreach ($history->registry()->redirects() as $rule) {
            $from = rtrim($rule['from'], '/');
            $to = $rule['to'];
            $lines[] = $from.' '.$to.' 301';
            $lines[] = $from.'/ '.$to.' 301';
        }

        File::put($output.'/_redirects', implode("\n", array_unique($lines))."\n");
    }

    private function exportLegacyMap(string $output, HistoryRecorder $history): void
    {
        $ids = [];
        $japanHashes = [];

        foreach ($history->registry()->movies() as $key => $movie) {
            foreach ($movie['legacyIds'] ?? [] as $legacyId) {
                $legacyId = (string) $legacyId;
                $ids[$legacyId] = $key;
                $fromSlug = str_starts_with($legacyId, 'global_')
                    ? substr($legacyId, strlen('global_'))
                    : $legacyId;
                $ids[$fromSlug] = $key;
                $hash = MovieIdentity::japanHashFromLegacyId($legacyId);
                if ($hash) {
                    $japanHashes[$hash] = $key;
                }
            }
        }

        File::put($output.'/data/legacy-map.json', json_encode([
            'ids' => $ids,
            'japanHashes' => $japanHashes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function exportFeed(string $output, array $globalInsights, array $japanInsights): void
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $items = [];
        foreach (array_merge($japanInsights['today'], $globalInsights['today']) as $movie) {
            $items[] = [
                'title' => ($movie['title'] ?? '').' '.$movie['deltaLabel'],
                'link' => $baseUrl.'/movies/'.$movie['key'].'/',
                'date' => $movie['lastChangeAt'] ?? $movie['lastObservedAt'] ?? now('Asia/Tokyo')->toAtomString(),
                'body' => trim(($movie['deltaLabel'] ?? '').' / '.($movie['dailyPaceLabel'] ?? '').' / '.($movie['passedLabel'] ?? '')),
            ];
        }
        foreach (array_merge($japanInsights['milestones'], $globalInsights['milestones']) as $milestone) {
            $items[] = [
                'title' => ($milestone['title'] ?? '').'が'.$milestone['label'],
                'link' => $baseUrl.'/movies/'.$milestone['key'].'/',
                'date' => $milestone['reachedAt'],
                'body' => ($milestone['daysToReach'] !== null ? '公開'.$milestone['daysToReach'].'日目（発表ベース）' : '発表ベース'),
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        $items = array_slice($items, 0, 30);
        $now = now('Asia/Tokyo')->toRfc2822String();

        $entries = '';
        foreach ($items as $item) {
            $date = Carbon::parse($item['date'])->toRfc2822String();
            $entries .= '    <item>'
                .'<title>'.$this->h($item['title']).'</title>'
                .'<link>'.$this->h($item['link']).'</link>'
                .'<guid>'.$this->h($item['link'].'#'.$item['date']).'</guid>'
                .'<pubDate>'.$this->h($date).'</pubDate>'
                .'<description>'.$this->h($item['body']).'</description>'
                ."</item>\n";
        }

        File::put($output.'/feed.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>MUBIRAN 公開中の興行収入</title>
    <link>{$baseUrl}/now/</link>
    <description>公開中作品の興行収入の伸びとマイルストーン</description>
    <language>ja</language>
    <lastBuildDate>{$now}</lastBuildDate>
{$entries}  </channel>
</rss>
XML);
    }

    private function exportNowPage(string $output): void
    {
        File::ensureDirectoryExists($output.'/now/global');
        File::put($output.'/now/index.html', $this->indexHtml('/now/'));
        File::put($output.'/now/global/index.html', $this->indexHtml('/now/global/'));
    }

    /**
     * @param  list<array<string, mixed>>  $japan
     */
    private function exportJapanRankingPage(string $output, array $japan, ?string $updatedAt): void
    {
        File::ensureDirectoryExists($output.'/japan');
        File::put($output.'/japan/index.html', $this->indexHtml('/japan/', $japan, $updatedAt));
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function movieHistoryHtml(array $movie, bool $isJapan): string
    {
        $history = is_array($movie['pageHistory'] ?? null) ? $movie['pageHistory'] : [];
        $sections = [];

        $next = $history['nextToOvertake'] ?? null;
        if (is_array($next) && ! empty($next['title']) && ! empty($next['remainingLabel'])) {
            $href = '/movies/'.$this->h((string) ($next['key'] ?? '')).'/';
            $rankSuffix = is_numeric($next['rank'] ?? null)
                ? 'を抜いて第'.(int) $next['rank'].'位'
                : 'を抜く';
            $sections[] = '<section class="movie-next">'
                .'<h2>次に抜く作品</h2>'
                .'<p>あと'.$this->h((string) $next['remainingLabel'])
                .'で<a href="'.$href.'">『'.$this->h((string) $next['title']).'』</a>'
                .$rankSuffix.'</p>'
                .'</section>';
        }

        $trajectory = $this->trajectorySection($movie, $isJapan);
        if ($trajectory !== '') {
            $sections[] = $trajectory;
        }

        $milestoneItems = [];
        foreach ($history['milestones'] ?? [] as $milestone) {
            if (! is_array($milestone) || ! isset($milestone['threshold'])) {
                continue;
            }
            $amount = Insights::formatAmount((int) $milestone['threshold'], $isJapan);
            $days = $milestone['daysToReach'] ?? null;
            $line = $days !== null
                ? $amount.' — 公開'.(int) $days.'日目（発表ベース）'
                : $amount.' — 発表ベース';
            $milestoneItems[] = '<li>'.$this->h($line).'</li>';
        }
        if ($milestoneItems !== []) {
            $sections[] = '<section class="movie-section movie-speed"><h2>到達スピード</h2><ul class="movie-speed-list">'
                .implode('', $milestoneItems)
                .'</ul></section>';
        }

        $momentum = $movie['momentum'] ?? null;
        if (is_array($momentum) && ! empty($momentum['hasHistory'])) {
            $items = [];
            if (! empty($momentum['deltaLabel'])) {
                $label = $momentum['deltaLabel'];
                if (! empty($momentum['daysSincePrev'])) {
                    $label .= '（'.$momentum['daysSincePrev'].'日前の発表比）';
                }
                $items[] = '<div><dt>前回からの伸び</dt><dd>'.$this->h($label).'</dd></div>';
            }
            if (! empty($momentum['dailyPaceLabel'])) {
                $items[] = '<div><dt>1日あたり</dt><dd>'.$this->h($momentum['dailyPaceLabel']).'</dd></div>';
            }
            if (! empty($momentum['rankDeltaLabel'])) {
                $items[] = '<div><dt>順位変動</dt><dd>'.$this->h($momentum['rankDeltaLabel']).'</dd></div>';
            }
            if (! empty($momentum['passedLabel'])) {
                $items[] = '<div><dt>追い抜き</dt><dd>'.$this->h($momentum['passedLabel']).'</dd></div>';
            }
            if ($items !== []) {
                $sections[] = '<section class="movie-section hit-scale"><h2>最近の伸び</h2><dl class="movie-stats">'.implode('', $items).'</dl></section>';
            }
        }

        if ($sections === []) {
            return '';
        }

        return implode('', $sections)
            .'<p class="hit-scale-disclaimer">発表ベースの記録です。チケット料金のインフレは未調整です。</p>';
    }

    /**
     * 公開中の作品だけを比較対象にする。累計が大きい順。
     *
     * @param  array<string, mixed>  $insights
     * @return list<array{key: string, title: string, color: string, points: list<array{at: string, ts: int, day: int, boxOffice: int}>}>
     */
    private function buildActiveSeries(array $insights, bool $isJapan): array
    {
        $series = [];
        foreach ($insights['movies'] ?? [] as $key => $insight) {
            if (! is_array($insight) || empty($insight['isActive'])) {
                continue;
            }
            [$chartDate, $chartPrecision] = $this->chartOrigin($insight);
            $points = $this->chartRows(
                $insight['sparkline'] ?? [],
                $chartDate,
                $chartPrecision,
                $isJapan,
            );
            if (count($points) < 2 || ($points[0]['day'] ?? null) === null) {
                continue;
            }
            $series[] = [
                'key' => (string) $key,
                'title' => (string) ($insight['title'] ?? $key),
                'color' => '',
                'points' => $points,
            ];
        }

        usort($series, fn (array $a, array $b) => $this->lastChartValue($b['points']) <=> $this->lastChartValue($a['points']));
        foreach ($series as $index => $item) {
            $series[$index]['color'] = self::COMPARE_COLORS[$index % count(self::COMPARE_COLORS)];
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function trajectorySection(array $movie, bool $isJapan): string
    {
        $history = is_array($movie['pageHistory'] ?? null) ? $movie['pageHistory'] : [];
        $self = $this->chartRows(
            $history['sparkline'] ?? [],
            $history['releaseDate'] ?? ($movie['releaseDate'] ?? null),
            $history['releaseDatePrecision'] ?? ($movie['releaseDatePrecision'] ?? null),
            $isJapan,
        );
        $solo = $this->chartSvg($self, [], $isJapan, 'solo', (string) ($movie['title'] ?? 'この作品'));
        if ($solo === '') {
            return '';
        }

        $fromRelease = ($self[0]['day'] ?? null) !== null;
        $soloZoomed = str_contains($solo, 'data-y-zoomed="1"');
        $soloPanel = '<div class="movie-chart-panel" data-chart="solo">'.$solo
            .$this->chartCaptionHtml($fromRelease, $soloZoomed).'</div>';
        $table = $this->trajectoryTable($self, $isJapan);

        $others = [];
        if (! empty($movie['isActive']) && $fromRelease) {
            $selfKey = (string) ($history['seriesKey'] ?? $movie['id'] ?? '');
            $selfTitle = (string) ($movie['title'] ?? '');
            foreach ($this->activeSeries[$isJapan ? 'japan' : 'global'] as $item) {
                if ($item['key'] === $selfKey || $item['title'] === $selfTitle) {
                    continue;
                }
                $others[] = $item;
            }
        }

        $compare = $others === [] ? '' : $this->chartSvg($self, $others, $isJapan, 'compare', (string) ($movie['title'] ?? 'この作品'));
        if ($compare === '') {
            return '<section class="movie-section movie-trajectory"><h2>興収の推移</h2>'.$soloPanel.$table.'</section>';
        }

        $legend = '<p class="movie-chart-legend"><span class="movie-chart-key is-self">'
            .$this->h((string) ($movie['title'] ?? 'この作品')).'</span>';
        foreach (array_slice($others, 0, 8) as $item) {
            $legend .= '<span class="movie-chart-key" style="--swatch:'.$this->h($item['color']).'">'
                .$this->h($item['title']).'</span>';
        }
        $legend .= '</p>';

        return '<section class="movie-section movie-trajectory">'
            .'<input class="movie-chart-mode" type="radio" name="chartMode" id="chartSolo" checked>'
            .'<input class="movie-chart-mode" type="radio" name="chartMode" id="chartCompare">'
            .'<div class="movie-chart-head"><h2>興収の推移</h2>'
            .'<span class="movie-chart-tabs">'
            .'<label for="chartSolo">この作品</label>'
            .'<label for="chartCompare">公開中と比較</label>'
            .'</span></div>'
            .$soloPanel
            .'<div class="movie-chart-panel" data-chart="compare">'.$compare.$legend
            .$this->chartCaptionHtml(true, str_contains($compare, 'data-y-zoomed="1"'), true)
            .'</div>'.$table.'</section>';
    }

    /**
     * グラフでは差が小さく見える変化も、日数と累計の対応で読めるようにする。
     *
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $rows
     */
    private function trajectoryTable(array $rows, bool $isJapan): string
    {
        if (count($rows) < 2) {
            return '';
        }

        $fromRelease = $this->chartHasDays($rows);
        $origin = Carbon::createFromTimestamp((int) $rows[0]['ts'])->timezone('Asia/Tokyo')->startOfDay();
        $lines = [];
        $previous = null;
        $lastIndex = array_key_last($rows);

        foreach ($rows as $index => $row) {
            $at = Carbon::createFromTimestamp((int) $row['ts'])->timezone('Asia/Tokyo');
            if ($fromRelease) {
                $day = (int) $row['day'];
                $dayLabel = $day === 0 ? '当日' : $day.'日';
            } else {
                $day = (int) floor(($at->copy()->startOfDay()->getTimestamp() - $origin->getTimestamp()) / 86400);
                $dayLabel = $day === 0 ? '初日' : $day.'日';
            }

            if ($previous === null) {
                $deltaLabel = '—';
                $deltaClass = '';
            } else {
                $delta = (int) $row['boxOffice'] - (int) $previous['boxOffice'];
                if ($delta > 0) {
                    $deltaLabel = Insights::formatDelta($delta, $isJapan);
                    $deltaClass = ' is-up';
                } elseif ($delta < 0) {
                    $deltaLabel = '−'.Insights::formatAmount(abs($delta), $isJapan);
                    $deltaClass = ' is-down';
                } else {
                    $deltaLabel = '±0';
                    $deltaClass = '';
                }
            }

            $lines[] = '<tr'.($index === $lastIndex ? ' class="is-latest"' : '').'>'
                .'<th scope="row">'.$this->h($dayLabel).'</th>'
                .'<td>'.$this->h($at->format('Y/n/j')).'</td>'
                .'<td class="is-amount">'.$this->h(Insights::formatAmount((int) $row['boxOffice'], $isJapan)).'</td>'
                .'<td class="is-delta'.$deltaClass.'">'.$this->h($deltaLabel).'</td>'
                .'</tr>';
            $previous = $row;
        }

        $body = implode('', array_reverse($lines));

        return '<div class="movie-chart-table-wrap">'
            .'<table class="movie-chart-table">'
            .'<caption>増分は前回発表との差。</caption>'
            .'<thead><tr>'
            .'<th scope="col">'.($fromRelease ? '公開日数' : '経過日数').'</th>'
            .'<th scope="col">発表日</th>'
            .'<th scope="col">累計</th>'
            .'<th scope="col">増分</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /**
     * @param  list<array{at?: string, boxOffice?: int}>  $points
     */
    private function trajectorySvg(array $points, bool $isJapan, ?string $releaseDate = null): string
    {
        return $this->chartSvg($this->chartRows($points, $releaseDate, $releaseDate ? 'day' : null, $isJapan), [], $isJapan, 'test');
    }

    /**
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $self
     * @param  list<array{key: string, title: string, color: string, points: list<array{at: string, ts: int, day: int|null, boxOffice: int}>}>  $others
     */
    private function chartSvg(array $self, array $others, bool $isJapan, string $id, string $selfTitle = ''): string
    {
        if (count($self) < 2) {
            return '';
        }

        $selfValues = array_column($self, 'boxOffice');
        if (min($selfValues) === max($selfValues)) {
            return '';
        }

        $useDays = $this->chartHasDays($self);
        foreach ($others as $other) {
            if (! $this->chartHasDays($other['points'])) {
                return '';
            }
        }

        $peak = max($selfValues);
        $floor = min($selfValues);
        if ($useDays) {
            $days = array_map(fn (array $row) => (int) $row['day'], $self);
            foreach ($others as $other) {
                $days = array_merge($days, array_map(fn (array $row) => (int) $row['day'], $other['points']));
                $peak = max($peak, max(array_column($other['points'], 'boxOffice')));
                $floor = min($floor, min(array_column($other['points'], 'boxOffice')));
            }
            $xMin = $others === [] ? min(array_map(fn (array $row) => (int) $row['day'], $self)) : 0;
            $xMax = max($days);
        } else {
            $xs = array_column($self, 'ts');
            foreach ($others as $other) {
                $xs = array_merge($xs, array_column($other['points'], 'ts'));
                $peak = max($peak, max(array_column($other['points'], 'boxOffice')));
                $floor = min($floor, min(array_column($other['points'], 'boxOffice')));
            }
            $xMin = min($xs);
            $xMax = max($xs);
        }
        if ($xMax === $xMin) {
            $xMax = $xMin + 1;
        }

        [$yMin, $yMax, $yTicks, $yZoomed] = $this->chartYDomain($floor, $peak, $isJapan);
        $yTickLabels = array_map(
            fn (float $tick): string => $this->chartAxisAmount($tick, $isJapan),
            $yTicks,
        );

        $width = 640;
        $height = 236;
        $padL = 62.0;
        foreach ($yTickLabels as $label) {
            $padL = max($padL, $this->chartLabelWidth($label) + 16);
        }
        $padR = 18;
        $padT = 28;
        $padB = 36;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;
        $baseY = $padT + $plotH;
        $xRight = $padL + $plotW;
        $xPos = fn (int|float $x): float => $padL + (($x - $xMin) / ($xMax - $xMin)) * $plotW;
        $yPos = function (int|float $value) use ($padT, $plotH, $yMin, $yMax): float {
            $span = $yMax - $yMin;
            if ($span <= 0.0) {
                return $padT + $plotH / 2;
            }

            return $padT + (1 - (($value - $yMin) / $span)) * $plotH;
        };
        $xOf = fn (array $row): float => $xPos($useDays ? (int) $row['day'] : (int) $row['ts']);

        $guides = '';
        foreach ($yTicks as $index => $threshold) {
            $gy = $yPos($threshold);
            $guides .= sprintf(
                '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="movie-chart-guide"/>',
                $padL,
                $gy,
                $xRight,
                $gy,
            );
            $guides .= '<text x="'.sprintf('%.1f', $padL - 8).'" y="'.sprintf('%.1f', $gy + 3.5)
                .'" text-anchor="end" class="movie-chart-guide-label">'
                .$this->h($yTickLabels[$index]).'</text>';
        }

        $othersSvg = '';
        foreach ($others as $index => $other) {
            $color = $other['color'] ?: self::COMPARE_COLORS[$index % count(self::COMPARE_COLORS)];
            $points = [];
            foreach ($other['points'] as $row) {
                $points[] = sprintf('%.1f,%.1f', $xOf($row), $yPos($row['boxOffice']));
            }
            $othersSvg .= '<g class="movie-chart-other-g" style="color:'.$this->h($color).'">'
                .'<polyline class="movie-chart-other-hit" points="'.implode(' ', $points).'"/>'
                .'<polyline class="movie-chart-other" points="'.implode(' ', $points).'"/>'
                .'</g>';
        }

        $coords = [];
        $dots = '';
        foreach ($self as $row) {
            $px = $xOf($row);
            $py = $yPos($row['boxOffice']);
            $coords[] = sprintf('%.1f,%.1f', $px, $py);
            $dots .= sprintf('<circle cx="%.1f" cy="%.1f" r="3.2" class="movie-chart-dot"/>', $px, $py);
        }
        $valueLabels = ($others === [] && count($self) <= 12)
            ? $this->chartValueLabels($self, $xOf, $yPos, $padL, $plotW, $width, $height, $isJapan)
            : '';
        $lastRow = $self[array_key_last($self)];
        $lastX = $xOf($lastRow);
        $lastY = $yPos($lastRow['boxOffice']);
        $dots .= sprintf('<circle cx="%.1f" cy="%.1f" r="4.4" class="movie-chart-dot is-last"/>', $lastX, $lastY);

        $polyline = implode(' ', $coords);
        $area = 'M '.sprintf('%.1f,%.1f', $xOf($self[0]), $baseY)
            .' L '.$polyline
            .' L '.sprintf('%.1f,%.1f', $lastX, $baseY).' Z';

        $xTicks = $this->chartXTicks($self, $others, $useDays);
        $axis = '<line x1="'.sprintf('%.1f', $padL).'" y1="'.sprintf('%.1f', $baseY)
            .'" x2="'.sprintf('%.1f', $xRight).'" y2="'.sprintf('%.1f', $baseY).'" class="movie-chart-baseline"/>';
        foreach ($xTicks as $tick) {
            $tx = $xPos($tick['x']);
            $anchor = 'middle';
            if ($tx <= $padL + 8) {
                $anchor = 'start';
            } elseif ($tx >= $xRight - 8) {
                $anchor = 'end';
            }
            $axis .= '<text x="'.sprintf('%.1f', $tx).'" y="'.($height - 10).'" text-anchor="'.$anchor.'" class="movie-chart-axis">'
                .$this->h($tick['label']).'</text>';
        }

        $fillId = 'chartFill-'.$this->h($id);
        $defs = '<defs><linearGradient id="'.$fillId.'" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0%" stop-color="#FFD700" stop-opacity="0.32"/>'
            .'<stop offset="100%" stop-color="#FFD700" stop-opacity="0"/>'
            .'</linearGradient></defs>';

        $chartClass = $others === [] ? 'movie-chart' : 'movie-chart is-compare';
        $aria = $yZoomed
            ? '興行収入の推移。縦軸は0からではなく、変化が見える範囲に拡大'
            : '興行収入の推移';
        $svg = '<svg class="'.$chartClass.'" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="'.$aria.'">'
            .$defs
            .$guides
            .'<path class="movie-chart-area" fill="url(#'.$fillId.')" d="'.$area.'"/>'
            .$othersSvg
            .'<polyline class="movie-chart-line" points="'.$polyline.'"/>'
            .$dots
            .$valueLabels
            .$axis
            .'</svg>';

        $hits = $this->chartHitsHtml($self, $others, $selfTitle, $isJapan, $useDays, $xOf, $yPos, $width, $height);

        $zoomAttr = $yZoomed ? ' data-y-zoomed="1"' : '';

        return '<div class="movie-chart-frame"'.$zoomAttr.'>'.$svg.$hits.'</div>';
    }

    /**
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $self
     * @param  list<array{title: string, color: string, points: list<array{at: string, ts: int, day: int|null, boxOffice: int}>}>  $others
     * @param  callable(array<string, mixed>): float  $xOf
     * @param  callable(int): float  $yPos
     */
    private function chartHitsHtml(
        array $self,
        array $others,
        string $selfTitle,
        bool $isJapan,
        bool $useDays,
        callable $xOf,
        callable $yPos,
        int $width,
        int $height,
    ): string {
        $hits = '';
        $series = array_merge(
            [['title' => $selfTitle !== '' ? $selfTitle : 'この作品', 'color' => '#FFD700', 'points' => $self, 'self' => true]],
            array_map(fn (array $item) => $item + ['self' => false], $others),
        );
        foreach ($series as $item) {
            foreach ($item['points'] as $row) {
                $left = $xOf($row) / $width * 100;
                $top = $yPos($row['boxOffice']) / $height * 100;
                $dayLabel = $useDays
                    ? (((int) $row['day'] === 0) ? '公開当日' : '公開'.(int) $row['day'].'日')
                    : $this->chartTsLabel((int) $row['ts']);
                $amount = Insights::formatAmount((int) $row['boxOffice'], $isJapan);
                $title = (string) $item['title'];
                $line = $item['self']
                    ? '累計 '.$amount
                    : '『'.$title.'』 '.$amount;
                $hits .= '<button type="button" class="movie-chart-hit'.($item['self'] ? ' is-self' : '').'" style="left:'
                    .sprintf('%.2f', $left).'%;--hit-top:'.sprintf('%.2f', $top).'%;--hit-color:'.$this->h((string) $item['color'])
                    .'" aria-label="'.$this->h($dayLabel.' '.$line).'">'
                    .'<span class="movie-chart-vline"></span>'
                    .'<span class="movie-chart-tip"><strong>'.$this->h($dayLabel).'</strong>'
                    .'<span>'.$this->h($line).'</span></span></button>';
            }
        }

        return '<div class="movie-chart-hits">'.$hits.'</div>';
    }

    /**
     * @return list<array{at: string, ts: int, day: int|null, boxOffice: int}>
     */
    private function chartRows(mixed $points, ?string $releaseDate = null, ?string $precision = null, bool $isJapan = false): array
    {
        if (! is_array($points)) {
            return [];
        }

        $rows = [];
        foreach ($points as $point) {
            if (! is_array($point) || ! isset($point['boxOffice'], $point['at'])) {
                continue;
            }
            try {
                $at = Carbon::parse((string) $point['at'])->timezone('Asia/Tokyo');
            } catch (\Throwable) {
                continue;
            }
            $rows[] = [
                'at' => (string) $point['at'],
                'ts' => $at->getTimestamp(),
                'day' => $this->chartDayNumber($releaseDate, $precision, $at),
                'boxOffice' => (int) $point['boxOffice'],
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        return $this->collapseSameChartAmounts($rows, $isJapan);
    }

    /**
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $rows
     * @return list<array{at: string, ts: int, day: int|null, boxOffice: int}>
     */
    private function collapseSameChartAmounts(array $rows, bool $isJapan): array
    {
        $collapsed = [];
        foreach ($rows as $row) {
            $previous = $collapsed === [] ? null : $collapsed[array_key_last($collapsed)];
            if ($previous && $this->chartShortAmount((int) $previous['boxOffice'], $isJapan) === $this->chartShortAmount((int) $row['boxOffice'], $isJapan)) {
                continue;
            }
            $collapsed[] = $row;
        }

        return $collapsed;
    }

    /**
     * リバイバル中は、比較グラフの起点を今回の劇場公開日にする。
     *
     * @param  array<string, mixed>  $insight
     * @return array{0: ?string, 1: ?string}
     */
    private function chartOrigin(array $insight): array
    {
        if (! empty($insight['currentReleaseDate'])) {
            return [$insight['currentReleaseDate'], 'day'];
        }

        return [$insight['releaseDate'] ?? null, $insight['releaseDatePrecision'] ?? null];
    }

    private function chartDayNumber(?string $releaseDate, ?string $precision, Carbon $at): ?int
    {
        if (! $releaseDate || ($precision !== null && $precision !== 'day')) {
            return null;
        }

        try {
            $released = Carbon::parse($releaseDate)->timezone('Asia/Tokyo')->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $days = (int) floor(($at->copy()->startOfDay()->getTimestamp() - $released->getTimestamp()) / 86400);

        return max(0, $days);
    }

    /**
     * @param  list<array{day: int|null}>  $rows
     */
    private function chartHasDays(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['day'] === null) {
                return false;
            }
        }

        return $rows !== [];
    }

    /**
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $self
     * @param  list<array{points: list<array{at: string, ts: int, day: int|null, boxOffice: int}>}>  $others
     * @return list<array{x: int, label: string}>
     */
    private function chartXTicks(array $self, array $others, bool $useDays): array
    {
        $values = [];
        foreach (array_merge([$self], array_column($others, 'points')) as $rows) {
            foreach ($rows as $row) {
                $values[] = $useDays ? (int) $row['day'] : (int) $row['ts'];
            }
        }
        $values = array_values(array_unique($values));
        sort($values);
        if ($values === []) {
            return [];
        }

        $picked = $this->spaceChartTicks($values);

        $ticks = [];
        foreach ($picked as $value) {
            $ticks[] = [
                'x' => $value,
                'label' => $useDays
                    ? ($value === 0 ? '当日' : $value.'日')
                    : $this->chartTsLabel($value),
            ];
        }

        return $ticks;
    }

    /**
     * 横軸ラベルが重ならないよう、値の間隔を空けて選ぶ。両端は必ず残す。
     *
     * @param  list<int>  $sorted
     * @return list<int>
     */
    private function spaceChartTicks(array $sorted, int $maxTicks = 6): array
    {
        if (count($sorted) <= 2) {
            return $sorted;
        }

        $first = $sorted[0];
        $last = $sorted[array_key_last($sorted)];
        $span = max(1, $last - $first);
        $minGap = $span / max(3, $maxTicks - 1);

        $picked = [$first];
        foreach (array_slice($sorted, 1, -1) as $value) {
            if (count($picked) >= $maxTicks - 1) {
                break;
            }
            if ($value - $picked[array_key_last($picked)] >= $minGap) {
                $picked[] = $value;
            }
        }

        $prev = $picked[array_key_last($picked)];
        if ($last - $prev < $minGap && count($picked) > 1) {
            $picked[array_key_last($picked)] = $last;
        } elseif ($last !== $prev) {
            $picked[] = $last;
        }

        return array_values(array_unique($picked));
    }

    /**
     * 累計が上の方に固まっているときは、0からの余白を省いて変化の幅に縦軸を寄せる。
     * 公開直後から大きく伸びる作品は、これまでのどおり 0 を下端にする。
     *
     * @return array{0: float, 1: float, 2: list<float>, 3: bool}
     */
    private function chartYDomain(int $floor, int $peak, bool $isJapan): array
    {
        $zeroMax = (float) max(1, (int) ceil($peak * 1.12));
        $span = $peak - $floor;
        $occupancy = $span / $zeroMax;
        if ($floor <= 0 || $occupancy >= 0.5) {
            $ticks = array_map('floatval', $this->chartYTicks((int) $zeroMax, $isJapan));

            return [0.0, $zeroMax, $ticks, false];
        }

        $unit = 100_000_000.0;
        $scale = $this->chartZoomScale($floor / $unit, $peak / $unit);

        return [
            $scale['min'] * $unit,
            $scale['max'] * $unit,
            array_map(fn (float $oku): float => $oku * $unit, $scale['ticks']),
            true,
        ];
    }

    /**
     * @return array{min: float, max: float, ticks: list<float>}
     */
    private function chartZoomScale(float $minOku, float $maxOku): array
    {
        $span = max(0.01, $maxOku - $minOku);
        $paddedMin = max(0.0, $minOku - $span * 0.18);
        $paddedMax = $maxOku + $span * 0.28;
        $rough = max(0.01, ($paddedMax - $paddedMin) / 4);
        $exponent = (int) floor(log10($rough));
        $magnitude = 10.0 ** $exponent;
        $best = null;

        foreach ([1.0, 2.0, 2.5, 5.0] as $base) {
            foreach ([$magnitude / 10, $magnitude, $magnitude * 10] as $mag) {
                $step = $base * $mag;
                if ($step <= 0.0 || $step >= ($paddedMax - $paddedMin)) {
                    continue;
                }
                $domainMin = $this->chartRoundOku(floor(($paddedMin + 1e-9) / $step) * $step);
                $domainMax = $this->chartRoundOku(ceil(($paddedMax - 1e-9) / $step) * $step);
                if ($domainMax <= $domainMin) {
                    $domainMax = $this->chartRoundOku($domainMin + $step);
                }
                if ($domainMin > $minOku - $span * 0.05) {
                    $domainMin = $this->chartRoundOku(max(0.0, $domainMin - $step));
                }
                if ($domainMax < $maxOku + $span * 0.1) {
                    $domainMax = $this->chartRoundOku($domainMax + $step);
                }
                $ticks = $this->chartTicksInclusive($domainMin, $domainMax, $step);
                $count = count($ticks);
                if ($count < 2 || $count > 8) {
                    continue;
                }
                $domainSpan = max(0.01, $domainMax - $domainMin);
                $slackPenalty = ($domainSpan > $span * 2.4 || $domainSpan < $span * 1.2) ? 2 : 0;
                $countPenalty = ($count < 3 || $count > 6) ? 3 : 0;
                $rank = [$slackPenalty, $countPenalty, abs($count - 4), $this->chartStepDecimals($step), -$step];
                if ($best === null || $rank < $best['rank']) {
                    $best = [
                        'rank' => $rank,
                        'min' => $domainMin,
                        'max' => $domainMax,
                        'ticks' => $ticks,
                    ];
                }
            }
        }

        if ($best === null) {
            return [
                'min' => $paddedMin,
                'max' => $paddedMax,
                'ticks' => [$this->chartRoundOku($minOku), $this->chartRoundOku($maxOku)],
            ];
        }

        return [
            'min' => $best['min'],
            'max' => $best['max'],
            'ticks' => $best['ticks'],
        ];
    }

    /**
     * @return list<float>
     */
    private function chartTicksInclusive(float $domainMin, float $domainMax, float $step): array
    {
        $first = (int) round($domainMin / $step);
        $last = (int) round($domainMax / $step);
        $ticks = [];
        for ($i = $first; $i <= $last; $i++) {
            $ticks[] = $this->chartRoundOku($i * $step);
        }

        return $ticks;
    }

    private function chartStepDecimals(float $step): int
    {
        $rounded = round($step, 6);
        if (abs($rounded - round($rounded)) < 1e-6) {
            return 0;
        }
        if (abs($rounded * 10 - round($rounded * 10)) < 1e-6) {
            return 1;
        }

        return 2;
    }

    private function chartRoundOku(float $oku): float
    {
        return round($oku, 4);
    }

    /**
     * @return list<int>
     */
    private function chartYTicks(int $yMax, bool $isJapan): array
    {
        $oku = $yMax / 100_000_000;
        if ($isJapan) {
            $step = $oku > 120 ? 50.0 : ($oku > 40 ? 20.0 : 10.0);
        } else {
            $step = $oku > 20 ? 5.0 : ($oku > 8 ? 2.0 : 1.0);
        }

        $ticks = [0];
        for ($value = $step; $value <= $oku + 0.001; $value += $step) {
            $ticks[] = (int) round($value * 100_000_000);
        }

        return $ticks;
    }

    private function chartCaptionHtml(bool $fromRelease, bool $zoomed, bool $compare = false): string
    {
        $text = $this->chartCaption($fromRelease, $zoomed, $compare);

        return $text === '' ? '' : '<p class="movie-chart-caption">'.$text.'</p>';
    }

    private function chartCaption(bool $fromRelease, bool $zoomed, bool $compare = false): string
    {
        $zoom = $zoomed ? '縦軸は変化が見える範囲に拡大しています。' : '';
        if ($compare) {
            return '公開日数を揃えて比較しています。'.$zoom;
        }
        if ($fromRelease) {
            return $zoom;
        }

        return '横軸は最初の観測からの日数です。'.$zoom;
    }

    /**
     * @return array{0: float, 1: 'start'|'middle'|'end'}
     */
    private function chartPointLabelPlacement(float $px, float $padL, float $plotW): array
    {
        if ($px <= $padL + 24) {
            return [$px + 8, 'start'];
        }
        if ($px >= $padL + $plotW * 0.82) {
            return [$px, 'end'];
        }

        return [$px, 'middle'];
    }

    /**
     * 点が近いときの数字は、上下にずらしても重なるものだけ省く。両端の数字は残す。
     *
     * @param  list<array{at: string, ts: int, day: int|null, boxOffice: int}>  $rows
     * @param  callable(array<string, mixed>): float  $xOf
     * @param  callable(int|float): float  $yPos
     */
    private function chartValueLabels(
        array $rows,
        callable $xOf,
        callable $yPos,
        float $padL,
        float $plotW,
        int $width,
        int $height,
        bool $isJapan,
    ): string {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'px' => $xOf($row),
                'py' => $yPos((int) $row['boxOffice']),
                'text' => $this->chartShortAmount((int) $row['boxOffice'], $isJapan),
            ];
        }

        $last = count($items) - 1;
        $order = [$last];
        if ($last > 0) {
            $order[] = 0;
        }
        for ($index = 1; $index < $last; $index++) {
            $order[] = $index;
        }

        $placed = [];
        $chosen = [];
        foreach ($order as $index) {
            $item = $items[$index];
            $labelWidth = $this->chartLabelWidth($item['text']) + 6;
            [$labelX, $anchor] = $this->chartPointLabelPlacement($item['px'], $padL, $plotW);
            $fit = null;
            foreach ([$item['py'] - 10, $item['py'] + 16, $item['py'] - 24, $item['py'] + 30] as $y) {
                if ($y < 12 || $y > $height - 18) {
                    continue;
                }
                $rect = $this->chartLabelRect($labelX, $y, $labelWidth, $anchor);
                if ($rect[0] < -2 || $rect[2] > $width + 2 || $this->chartRectsOverlap($rect, $placed, 8)) {
                    continue;
                }
                $fit = [$labelX, $y, $anchor, $rect];
                break;
            }
            if ($fit === null && ($index === 0 || $index === $last)) {
                $y = max(12.0, $item['py'] - 10);
                $fit = [$labelX, $y, $anchor, $this->chartLabelRect($labelX, $y, $labelWidth, $anchor)];
            }
            if ($fit === null) {
                continue;
            }
            $placed[] = $fit[3];
            $chosen[$index] = $fit;
        }

        ksort($chosen);
        $svg = '';
        foreach ($chosen as $index => $fit) {
            $svg .= '<text x="'.sprintf('%.1f', $fit[0]).'" y="'.sprintf('%.1f', $fit[1])
                .'" text-anchor="'.$fit[2].'" class="movie-chart-point-label">'
                .$this->h($items[$index]['text']).'</text>';
        }

        return $svg;
    }

    private function chartLabelWidth(string $text): float
    {
        $width = 0.0;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            $width += preg_match('/[0-9.,]/', $char) === 1 ? 6.2 : 10.5;
        }

        return $width;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function chartLabelRect(float $x, float $y, float $width, string $anchor): array
    {
        if ($anchor === 'start') {
            $x1 = $x;
        } elseif ($anchor === 'end') {
            $x1 = $x - $width;
        } else {
            $x1 = $x - $width / 2;
        }

        return [$x1, $y - 12, $x1 + $width, $y];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $placed
     */
    private function chartRectsOverlap(array $rect, array $placed, float $gap): bool
    {
        foreach ($placed as $other) {
            if ($rect[0] < $other[2] + $gap
                && $rect[2] + $gap > $other[0]
                && $rect[1] < $other[3] + $gap
                && $rect[3] + $gap > $other[1]) {
                return true;
            }
        }

        return false;
    }

    private function chartAxisAmount(int|float $amount, bool $isJapan): string
    {
        $formatted = $this->chartFormatOku($amount / 100_000_000);

        return $isJapan
            ? $formatted.'億円'
            : $formatted.'億ドル';
    }

    private function chartFormatOku(float $oku): string
    {
        $rounded = round($oku, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return number_format((int) round($rounded));
        }
        if (abs($rounded - round($rounded, 1)) < 0.001) {
            return number_format(round($rounded, 1), 1);
        }

        return number_format($rounded, 2);
    }

    private function chartShortAmount(int $amount, bool $isJapan): string
    {
        $oku = $amount / 100_000_000;

        return $isJapan
            ? rtrim(rtrim(number_format($oku, 1), '0'), '.').'億'
            : number_format($oku, 2).'億';
    }

    /**
     * @param  list<array{at: string, ts: int, boxOffice: int}>  $rows
     */
    private function lastChartValue(array $rows): int
    {
        return $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['boxOffice'];
    }

    private function chartTsLabel(int $ts): string
    {
        return Carbon::createFromTimestamp($ts)->timezone('Asia/Tokyo')->format('n/j');
    }

    private function exportMoviePages(string $output, $global, $japan): void
    {
        foreach ($global->values()->all() as $movie) {
            $this->writeMoviePage($output, $movie, false);
        }

        foreach ($japan->values()->all() as $movie) {
            $this->writeMoviePage($output, $movie, true);
        }
    }

    private function writeMoviePage(string $output, array $movie, bool $isJapan): void
    {
        $slug = $this->movieSlug($movie['id']);
        $dir = $output . '/movies/' . $slug;
        File::ensureDirectoryExists($dir);
        File::put($dir . '/index.html', $this->moviePageHtml($movie, $isJapan, $output));
    }

    private function loadExportedDetail(string $output, string $movieId): ?array
    {
        $paths = [
            $output . '/data/details/' . $movieId . '.json',
            storage_path('app/static-details/' . $movieId . '.json'),
        ];

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            $detail = json_decode(File::get($path), true);

            return is_array($detail) ? $detail : null;
        }

        return null;
    }

    private function formatReleaseDate(?string $date): string
    {
        if (! $date) {
            return '-';
        }

        try {
            return Carbon::parse($date)->format('Y年n月j日');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function absoluteUrl(?string $url): string
    {
        $baseUrl = rtrim(config('app.url'), '/');

        if (empty($url)) {
            return $baseUrl . '/images/android-chrome-512x512.png';
        }

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return $baseUrl . (str_starts_with($url, '/') ? $url : '/' . $url);
    }

    private function h(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function viteEntry(): array
    {
        $manifestPath = public_path('build/manifest.json');
        if (! File::exists($manifestPath)) {
            throw new \RuntimeException('Vite assets are missing. Run npm run build before site:export-static.');
        }

        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $entry = $manifest['resources/js/static-site.js'] ?? null;
        if (! $entry) {
            throw new \RuntimeException('Static site Vite entry was not found in the manifest.');
        }

        return $entry;
    }

    private function viteStyles(): string
    {
        $entry = $this->viteEntry();

        return collect($entry['css'] ?? [])
            ->map(fn (string $file) => '<link rel="stylesheet" href="/build/' . e($file) . '">')
            ->implode("\n    ");
    }

    private function viteScript(): string
    {
        return '/build/' . e($this->viteEntry()['file']);
    }

    private function exportSitemap(string $output, array $global, array $japan, array $boardOnly = []): void
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $defaultLastmod = now('Asia/Tokyo')->toAtomString();

        $urls = [[
            'loc' => "{$baseUrl}/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '1.0',
        ], [
            'loc' => "{$baseUrl}/japan/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '0.9',
        ], [
            'loc' => "{$baseUrl}/now/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '0.8',
        ], [
            'loc' => "{$baseUrl}/now/global/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '0.8',
        ], [
            'loc' => "{$baseUrl}/feed.xml",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '0.4',
        ], [
            'loc' => "{$baseUrl}/about/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'monthly',
            'priority' => '0.3',
        ], [
            'loc' => "{$baseUrl}/privacy/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'monthly',
            'priority' => '0.3',
        ]];

        foreach (array_merge($global, $japan, $boardOnly) as $movie) {
            $urls[] = [
                'loc' => "{$baseUrl}/movies/" . $this->movieSlug($movie['id']) . '/',
                'lastmod' => $this->movieLastmod($movie, $defaultLastmod),
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        $body = '';
        foreach ($urls as $url) {
            $body .= "    <url>\n";
            $body .= '        <loc>' . $this->h($url['loc']) . "</loc>\n";
            $body .= '        <lastmod>' . $this->h($url['lastmod']) . "</lastmod>\n";
            $body .= '        <changefreq>' . $url['changefreq'] . "</changefreq>\n";
            $body .= '        <priority>' . $url['priority'] . "</priority>\n";
            $body .= "    </url>\n";
        }

        File::put($output . '/sitemap.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $body
            . "</urlset>\n");
    }

    private function moviePageHtml(array $movie, bool $isJapan, string $output): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $detail = $this->loadExportedDetail($output, $movie['id']) ?? [];
        $slug = $this->movieSlug($movie['id']);
        $pageUrl = "{$baseUrl}/movies/{$slug}/";
        $homeUrl = $baseUrl.($isJapan ? '/japan/' : '/');

        $title = $movie['title'];
        $originalTitle = $detail['original_title'] ?? $movie['originalTitle'] ?? '';
        $overview = $detail['overview'] ?? '';
        $releaseDate = $detail['release_date'] ?? $movie['releaseDate'] ?? null;
        $rankLabel = $isJapan ? '日本ランキング' : '世界ランキング';
        $revenueText = $movie['revenue'] . ($movie['revenueYen'] ? "（{$movie['revenueYen']}）" : '');

        $rankValue = is_numeric($movie['rank'] ?? null) ? '第'.$movie['rank'].'位' : (string) ($movie['rank'] ?? '圏外');
        $pageTitle = "{$title} の興行収入 {$movie['revenue']} | {$rankLabel}{$rankValue} - MUBIRAN";
        $description = "{$title}の興行収入は{$revenueText}（{$rankLabel}{$rankValue}）。";
        if ($originalTitle && $originalTitle !== $title) {
            $description .= "原題: {$originalTitle}。";
        }
        $description .= '公開日: ' . $this->formatReleaseDate($releaseDate) . '。';
        if ($overview) {
            $description .= mb_substr($overview, 0, 120) . (mb_strlen($overview) > 120 ? '…' : '');
        }

        $keywords = implode(',', array_filter([
            $title,
            $originalTitle,
            '興行収入',
            '映画',
            $isJapan ? '日本映画' : '洋画',
            ...($movie['genres'] ?? []),
        ]));

        $ogImage = $this->absoluteUrl($movie['posterUrl']);
        $styles = $this->viteStyles();

        $subtitle = ($originalTitle && $originalTitle !== $title)
            ? '<p class="movie-original-title">' . $this->h($originalTitle) . '</p>'
            : '';

        $posterHtml = $movie['posterUrl']
            ? '<div class="movie-poster-frame"><img src="' . $this->h($this->absoluteUrl($movie['posterUrl'])) . '" alt="' . $this->h($title) . '" class="movie-poster" width="160" height="240" loading="lazy"></div>'
            : '';

        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'item' => ['@id' => "{$baseUrl}/", 'name' => 'ホーム'],
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'item' => ['@id' => $pageUrl, 'name' => $title],
                        ],
                    ],
                ],
                [
                    '@type' => 'Movie',
                    'name' => $title,
                    'alternateName' => $originalTitle ?: null,
                    'datePublished' => $releaseDate,
                    'description' => $overview ?: null,
                    'url' => $pageUrl,
                    'image' => $ogImage,
                    'inLanguage' => 'ja',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $eDescription = $this->h($description);
        $eKeywords = $this->h($keywords);
        $ePageUrl = $this->h($pageUrl);
        $ePageTitle = $this->h($pageTitle);
        $eOgImage = $this->h($ogImage);
        $eTitle = $this->h($title);
        $eRankLabel = $this->h($rankLabel);
        $eRankValue = $this->h($rankValue);
        $eRevenue = $this->h($movie['revenue']);
        $eRevenueYen = $this->h($isJapan ? ($movie['revenue'] ?? '-') : ($movie['revenueYen'] ?? '-'));
        $yenRow = $isJapan ? '' : "<div><dt>日本換算</dt><dd>{$eRevenueYen}</dd></div>\n            ";
        $eReleaseDate = $this->h($this->formatReleaseDate($releaseDate));
        $updatedOn = $this->formatRankingDate(is_string($movie['updatedAt'] ?? null) ? $movie['updatedAt'] : null);
        $updatedRow = $updatedOn !== null
            ? '<div><dt>最終更新</dt><dd>'.$this->h($updatedOn).'</dd></div>'."\n            "
            : '';
        $eHomeUrl = $this->h($homeUrl);
        $footerInner = $this->siteFooterInnerHtml();
        $historyHtml = $this->movieHistoryHtml($movie, $isJapan);
        $heading = $this->h($title.'の興行収入');
        $backParts = [];
        if (! empty($movie['isActive'])) {
            $nowPath = $isJapan ? '/now/' : '/now/global/';
            $backParts[] = '<a href="'.$this->h($nowPath).'">公開中の動向に戻る</a>';
        }
        $backParts[] = '<a href="'.$eHomeUrl.'">'.$eRankLabel.'一覧に戻る</a>';
        $backHtml = '<p class="movie-back-link">'.implode(' · ', $backParts).'</p>';

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$eDescription}">
    <meta name="keywords" content="{$eKeywords}">
    <meta name="robots" content="index, follow">
    <meta name="author" content="ムビラン">
    <meta name="language" content="ja">
    <link rel="canonical" href="{$ePageUrl}">
    <title>{$ePageTitle}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#2c3e50">
    <meta property="og:title" content="{$ePageTitle}">
    <meta property="og:description" content="{$eDescription}">
    <meta property="og:type" content="video.movie">
    <meta property="og:url" content="{$ePageUrl}">
    <meta property="og:image" content="{$eOgImage}">
    <meta property="og:locale" content="ja_JP">
    <meta property="og:site_name" content="MUBIRAN - 映画興行収入ランキング">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$ePageTitle}">
    <meta name="twitter:description" content="{$eDescription}">
    <meta name="twitter:image" content="{$eOgImage}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Oswald:wght@400;700&display=swap" rel="stylesheet">
    {$styles}
    <script type="application/ld+json">{$jsonLd}</script>
  </head>
  <body class="movie-page">
    <header>
      <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
    </header>
    <main class="container movie-page-main">
      <nav class="movie-breadcrumb" aria-label="パンくずリスト">
        <a href="/">ホーム</a>
        <span aria-hidden="true">›</span>
        <span>{$eTitle}</span>
      </nav>
      <article>
        <div class="movie-hero">
          {$posterHtml}
          <div class="movie-hero-body">
            <h1 class="movie-page-title">{$heading}</h1>
            {$subtitle}
          </div>
          <dl class="movie-hero-stats">
            <div><dt>{$eRankLabel}</dt><dd>{$eRankValue}</dd></div>
            <div><dt>興行収入</dt><dd>{$eRevenue}</dd></div>
            {$yenRow}{$updatedRow}<div><dt>公開日</dt><dd>{$eReleaseDate}</dd></div>
          </dl>
        </div>
        {$historyHtml}
      </article>
      {$backHtml}
    </main>
    <footer>
      <div class="container">
        {$footerInner}
      </div>
    </footer>
  </body>
</html>
HTML;
    }

    private function exportTrustPages(string $output): void
    {
        File::ensureDirectoryExists($output . '/about');
        File::ensureDirectoryExists($output . '/privacy');

        File::put($output . '/about/index.html', $this->aboutPageHtml());
        File::put($output . '/privacy/index.html', $this->privacyPageHtml());
        File::put($output . '/404.html', $this->notFoundPageHtml());
    }

    private function siteFooterInnerHtml(): string
    {
        $year = $this->h((string) now('Asia/Tokyo')->year);
        $usdJpy = $this->usdJpyLabel();
        $wikiUrl = 'https://ja.wikipedia.org/wiki/'.rawurlencode('日本歴代興行成績上位の映画一覧');
        $ccUrl = 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja';

        return <<<HTML
        <p class="site-footer-links"><a href="/now/">公開中の動向</a> · <a href="/about/">このサイトについて</a> · <a href="/privacy/">プライバシーポリシー</a> · <a href="/feed.xml">RSS</a></p>
        <p>&copy; {$year} MUBIRAN. All rights reserved.</p>
        <p class="site-footer-attr">
            <a class="tmdb-attr" href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">
                <img src="/images/tmdb-logo.svg" alt="The Movie Database (TMDB)" class="tmdb-logo" width="80" height="10">
            </a>
            日本の歴代興行収入は Wikipedia『<a href="{$wikiUrl}" target="_blank" rel="noreferrer">日本歴代興行成績上位の映画一覧</a>』（<a href="{$ccUrl}" target="_blank" rel="noreferrer">CC BY-SA 4.0</a>）を出典としています。
        </p>
        <p class="site-footer-disclaimer">This website uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.</p>
        <p class="site-footer-disclaimer">世界興収の円換算は 1ドル={$usdJpy}円の概算です。</p>
HTML;
    }

    private function contentPageHtml(
        string $path,
        string $pageTitle,
        string $metaDescription,
        string $breadcrumbLabel,
        string $heading,
        string $bodyHtml,
        string $robots = 'index, follow',
    ): string {
        $baseUrl = rtrim(config('app.url'), '/');
        $canonical = $baseUrl . $path;
        $ogImage = $baseUrl . '/images/android-chrome-512x512.png';
        $styles = $this->viteStyles();

        $ePageTitle = $this->h($pageTitle);
        $eDescription = $this->h($metaDescription);
        $eCanonical = $this->h($canonical);
        $eOgImage = $this->h($ogImage);
        $eBreadcrumb = $this->h($breadcrumbLabel);
        $eHeading = $this->h($heading);
        $footer = $this->siteFooterInnerHtml();

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$eDescription}">
    <meta name="robots" content="{$robots}">
    <meta name="author" content="ムビラン">
    <meta name="language" content="ja">
    <link rel="canonical" href="{$eCanonical}">
    <title>{$ePageTitle}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#2c3e50">
    <meta property="og:title" content="{$ePageTitle}">
    <meta property="og:description" content="{$eDescription}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{$eCanonical}">
    <meta property="og:image" content="{$eOgImage}">
    <meta property="og:locale" content="ja_JP">
    <meta property="og:site_name" content="MUBIRAN - 映画興行収入ランキング">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$ePageTitle}">
    <meta name="twitter:description" content="{$eDescription}">
    <meta name="twitter:image" content="{$eOgImage}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Oswald:wght@400;700&display=swap" rel="stylesheet">
    {$styles}
  </head>
  <body class="content-page">
    <header>
      <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
    </header>
    <main class="container content-page-main">
      <nav class="content-breadcrumb" aria-label="パンくずリスト">
        <a href="/">ホーム</a>
        <span aria-hidden="true">›</span>
        <span>{$eBreadcrumb}</span>
      </nav>
      <article class="content-article">
        <h1 class="content-page-title">{$eHeading}</h1>
        {$bodyHtml}
      </article>
    </main>
    <footer>
      <div class="container">
        {$footer}
      </div>
    </footer>
  </body>
</html>
HTML;
    }

    private function aboutPageHtml(): string
    {
        $contactEmail = $this->h((string) config('app.contact_email'));
        $usdJpy = $this->h($this->usdJpyLabel());
        $wikiUrl = 'https://ja.wikipedia.org/wiki/'.rawurlencode('日本歴代興行成績上位の映画一覧');
        $ccUrl = 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja';
        $body = <<<HTML
        <section class="content-section">
          <h2>MUBIRAN（ムビラン）とは</h2>
          <p>MUBIRANは、世界と日本の映画興行収入ランキングをわかりやすく掲載する情報サイトです。歴代ヒット作の興行成績に加え、公開中作品の伸びや順位変動も追えます。</p>
        </section>
        <section class="content-section">
          <h2>公開中の動向について</h2>
          <p>日本の興行収入は配給会社の発表ベースで更新されるため、毎日数字が動くわけではありません。当サイトは発表のたびに記録し、前回発表からの伸び・1日あたりのペース・順位変動を表示します。チケット料金のインフレは未調整です。</p>
        </section>
        <section class="content-section">
          <h2>掲載データについて</h2>
          <p>興行収入・作品情報は、主に以下の公開データをもとに整理・掲載しています。</p>
          <ul>
            <li>世界の作品情報・ポスター: <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDB（The Movie Database）</a></li>
            <li>日本の歴代興行収入: Wikipedia『<a href="{$wikiUrl}" target="_blank" rel="noreferrer">日本歴代興行成績上位の映画一覧</a>』（<a href="{$ccUrl}" target="_blank" rel="noreferrer">CC BY-SA 4.0</a>）</li>
          </ul>
          <p class="tmdb-about-logo"><a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer"><img src="/images/tmdb-logo.svg" alt="The Movie Database (TMDB)" class="tmdb-logo" width="120" height="16"></a></p>
          <p>This website uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.</p>
          <p>日本の興行収入は配給会社の発表ベースです。公開中作品の数字は毎日は動きません。世界興収の円換算は 1ドル={$usdJpy}円の概算です。チケット料金のインフレは未調整です。</p>
          <p>表示内容は参考情報であり、公式の興行成績と異なる場合があります。</p>
        </section>
        <section class="content-section">
          <h2>データの更新</h2>
          <p>ランキングデータは毎日自動で更新されます。作品詳細やポスター画像も、定期的に再取得・反映されます。</p>
        </section>
        <section class="content-section">
          <h2>AI分析について</h2>
          <p>一部の作品には、興行成績や話題性をもとにしたAIによる解説文を掲載しています。これらは自動生成された参考情報であり、事実確認済みの評論ではありません。</p>
        </section>
        <section class="content-section">
          <h2>免責事項</h2>
          <p>本サイトの情報は正確性の確保に努めていますが、その完全性・最新性を保証するものではありません。掲載内容を利用したことによって生じた損害について、当サイトは一切の責任を負いません。</p>
        </section>
        <section class="content-section">
          <h2>お問い合わせ</h2>
          <p>掲載内容の修正依頼やお問い合わせは、<a href="mailto:{$contactEmail}">{$contactEmail}</a> までご連絡ください。</p>
        </section>
        <p class="content-back-link"><a href="/">ランキングトップへ戻る</a></p>
HTML;

        return $this->contentPageHtml(
            '/about/',
            'このサイトについて | MUBIRAN',
            'MUBIRAN（ムビラン）は世界・日本の映画興行収入ランキングを掲載するサイトです。データの出典、更新頻度、免責事項をご案内します。',
            'このサイトについて',
            'このサイトについて',
            $body,
        );
    }

    private function privacyPageHtml(): string
    {
        $updated = now('Asia/Tokyo')->format('Y年n月j日');
        $eUpdated = $this->h($updated);
        $contactEmail = $this->h((string) config('app.contact_email'));
        $body = <<<HTML
        <p class="content-updated">最終更新日: {$eUpdated}</p>
        <section class="content-section">
          <h2>基本方針</h2>
          <p>MUBIRAN（以下「当サイト」）は、利用者のプライバシーを尊重し、個人情報の保護に努めます。本ポリシーは、当サイトの利用に関する情報の取り扱いについて説明するものです。</p>
        </section>
        <section class="content-section">
          <h2>収集する情報</h2>
          <p>当サイトは、利用者が自ら入力しない限り、氏名・住所・電話番号などの個人を特定できる情報を収集しません。ただし、ホスティング事業者（Cloudflare）やアクセス解析の仕組みにより、以下の情報が自動的に記録される場合があります。</p>
          <ul>
            <li>IPアドレス</li>
            <li>ブラウザの種類・バージョン</li>
            <li>アクセス日時・参照元URL</li>
            <li>閲覧したページのURL</li>
          </ul>
        </section>
        <section class="content-section">
          <h2>Cookie・ローカルストレージ</h2>
          <p>当サイトでは、表示の利便性向上のため Service Worker を利用することがあります。これらのデータは端末内にのみ保存され、広告配信や第三者への販売には使用しません。</p>
        </section>
        <section class="content-section">
          <h2>第三者サービス</h2>
          <p>当サイトは、以下の外部サービスを利用しています。各サービスにおけるデータの取り扱いについては、それぞれのプライバシーポリシーをご確認ください。</p>
          <ul>
            <li><a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noreferrer">Cloudflare</a>（ホスティング・CDN）</li>
            <li><a href="https://www.themoviedb.org/privacy-policy" target="_blank" rel="noreferrer">TMDb</a>（映画データ・画像）</li>
            <li><a href="https://policies.google.com/privacy" target="_blank" rel="noreferrer">Google</a>（フォント配信）</li>
          </ul>
        </section>
        <section class="content-section">
          <h2>外部リンク</h2>
          <p>当サイトからリンクされている第三者のウェブサイトについて、当サイトはその内容やプライバシー慣行に関して責任を負いません。</p>
        </section>
        <section class="content-section">
          <h2>お問い合わせ</h2>
          <p>本ポリシーに関するお問い合わせは、<a href="mailto:{$contactEmail}">{$contactEmail}</a> までご連絡ください。</p>
        </section>
        <p class="content-back-link"><a href="/">ランキングトップへ戻る</a></p>
HTML;

        return $this->contentPageHtml(
            '/privacy/',
            'プライバシーポリシー | MUBIRAN',
            'MUBIRAN（ムビラン）のプライバシーポリシーです。収集する情報、Cookie、第三者サービスについて説明します。',
            'プライバシーポリシー',
            'プライバシーポリシー',
            $body,
        );
    }

    private function notFoundPageHtml(): string
    {
        $body = <<<'HTML'
        <p class="content-lead">お探しのページは見つかりませんでした。URLが変更されたか、削除された可能性があります。</p>
        <p class="content-back-link"><a href="/">ランキングトップへ戻る</a></p>
        <script>
        (function () {
          var path = location.pathname.replace(/\/+$/, '');
          var prefix = '/movies/';
          if (path.indexOf(prefix) !== 0) return;
          var slug = decodeURIComponent(path.slice(prefix.length));
          if (!slug || slug.indexOf('/') !== -1) return;
          var global = slug.match(/^(\d{3})_(\d+)$/);
          if (global) {
            location.replace('/movies/tmdb-' + global[2] + '/');
            return;
          }
          var japan = slug.match(/^jp_\d{3}_([0-9a-f]{8})$/);
          fetch('/data/legacy-map.json').then(function (response) {
            return response.ok ? response.json() : null;
          }).then(function (map) {
            if (!map) return;
            var key = (map.ids && map.ids[slug]) || (japan && map.japanHashes && map.japanHashes[japan[1]]);
            if (key && key !== slug) {
              location.replace('/movies/' + encodeURIComponent(key) + '/');
            }
          }).catch(function () {});
        })();
        </script>
HTML;

        return $this->contentPageHtml(
            '/404.html',
            'ページが見つかりません | MUBIRAN',
            'お探しのページは見つかりませんでした。',
            '404',
            'ページが見つかりません',
            $body,
            'noindex, follow',
        );
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function movieLastmod(array $movie, string $fallback): string
    {
        $raw = $movie['updatedAt'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($raw)->timezone('Asia/Tokyo')->toAtomString();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function formatRankingDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone('Asia/Tokyo')->format('Y年n月j日');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     */
    private function rankingIntro(array $movies, ?string $updatedAt): string
    {
        $updated = $this->formatRankingDate($updatedAt);
        $top = $movies[0] ?? null;
        $hasTop = is_array($top) && ($top['title'] ?? '') !== '' && ($top['revenue'] ?? '') !== '';
        if (! $hasTop) {
            $text = '興行収入の高い順に掲載しています';
            if ($updated) {
                $text .= '（'.$updated.'更新）';
            }

            return $text.'。';
        }

        $text = '1位は『'.$top['title'].'』の'.$top['revenue'];
        if ($updated) {
            $text .= '（'.$updated.'更新）';
        }

        return $text.'。';
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     */
    private function rankingStaticHtml(array $movies, bool $isJapan, ?string $updatedAt): string
    {
        $heading = $isJapan ? '日本映画興行収入ランキング' : '世界映画興行収入ランキング';
        $intro = $this->rankingIntro($movies, $updatedAt);
        $otherHref = $isJapan ? '/' : '/japan/';
        $otherLabel = $isJapan ? '世界映画興行収入ランキング' : '日本映画興行収入ランキング';
        $items = '';
        foreach (array_slice($movies, 0, self::RANKING_PAGE_SIZE) as $movie) {
            $slug = $this->movieSlug((string) ($movie['slug'] ?? $movie['id'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $items .= '<li><span class="ranking-static-rank">'.$this->h((string) ($movie['rank'] ?? '')).'</span> '
                .'<a href="/movies/'.$this->h($slug).'/">'.$this->h((string) ($movie['title'] ?? '')).'</a> '
                .'<span class="ranking-static-revenue">'.$this->h((string) ($movie['revenue'] ?? '')).'</span></li>'."\n";
        }

        return '<main class="container ranking-static">'
            .'<h1 class="page-title">'.$this->h($heading).'</h1>'
            .'<p class="ranking-static-intro">'.$this->h($intro).'</p>'
            .'<ol class="ranking-static-list">'."\n".$items.'</ol>'
            .'<p class="ranking-static-switch"><a href="'.$this->h($otherHref).'">'.$this->h($otherLabel).'</a></p>'
            .'</main>';
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     */
    private function rankingItemListJson(array $movies, bool $isJapan): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $elements = [];
        foreach (array_slice($movies, 0, self::RANKING_PAGE_SIZE) as $offset => $movie) {
            $slug = $this->movieSlug((string) ($movie['slug'] ?? $movie['id'] ?? ''));
            $title = (string) ($movie['title'] ?? '');
            if ($slug === '' || $title === '') {
                continue;
            }
            $elements[] = [
                '@type' => 'ListItem',
                'position' => is_numeric($movie['rank'] ?? null) ? (int) $movie['rank'] : $offset + 1,
                'name' => $title,
                'url' => $baseUrl.'/movies/'.$slug.'/',
            ];
        }

        if ($elements === []) {
            return '';
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $isJapan ? '日本映画興行収入ランキング' : '世界映画興行収入ランキング',
            'itemListElement' => $elements,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return array{title: string, description: string, canonical: string, jsonLd: string, appHtml: string}
     */
    private function indexMeta(string $path, array $rankingMovies = [], ?string $rankingUpdated = null): array
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $canonical = $baseUrl.($path === '/' ? '/' : rtrim($path, '/').'/');
        $isNowGlobal = $path === '/now/global' || $path === '/now/global/';
        $isNow = str_starts_with($path, '/now');
        $isJapanRanking = $path === '/japan' || $path === '/japan/';
        $isRanking = ! $isNow && ($path === '/' || $isJapanRanking);

        if ($isNowGlobal) {
            $title = '公開中映画の興行収入の勢い（世界） | MUBIRAN';
            $description = '世界で公開中の映画がどれくらいのペースで興行収入を伸ばしているかを追跡。前回発表からの伸び、1日あたりのペース、マイルストーン到達を毎日確認できます。';
        } elseif ($isNow) {
            $title = '公開中映画の興行収入の勢い（日本） | MUBIRAN';
            $description = '日本で公開中の映画がどれくらいのペースで興行収入を伸ばしているかを追跡。前回発表からの伸び、1日あたりのペース、マイルストーン到達を毎日確認できます。';
        } elseif ($isJapanRanking) {
            $title = '日本映画興行収入ランキング | MUBIRAN';
            $description = '日本映画の歴代興行収入ランキング。'.$this->rankingIntro($rankingMovies, $rankingUpdated);
        } else {
            $title = '世界映画興行収入ランキング | MUBIRAN';
            $description = '世界映画の歴代興行収入ランキング。'.$this->rankingIntro($rankingMovies, $rankingUpdated);
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'jsonLd' => $isRanking ? $this->rankingItemListJson($rankingMovies, $isJapanRanking) : '',
            'appHtml' => $isRanking ? $this->rankingStaticHtml($rankingMovies, $isJapanRanking, $rankingUpdated) : '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     */
    private function indexHtml(string $path = '/', array $rankingMovies = [], ?string $rankingUpdated = null): string
    {
        $styles = $this->viteStyles();
        $script = $this->viteScript();
        $meta = $this->indexMeta($path, $rankingMovies, $rankingUpdated);
        $title = $this->h($meta['title']);
        $description = $this->h($meta['description']);
        $canonical = $this->h($meta['canonical']);
        $keywords = $this->h('映画,興行収入,ランキング,売上,日本映画,世界の映画,最新,ムビラン,ボックスオフィス,映画統計,映画データ,映画売上,興行成績,映画ランキング,最新映画,公開中');
        $ogImage = $this->h(rtrim(config('app.url'), '/').'/images/android-chrome-512x512.png');
        $jsonLd = $meta['jsonLd'];
        $appHtml = $meta['appHtml'];

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$description}">
    <meta name="keywords" content="{$keywords}">
    <meta name="robots" content="index, follow">
    <meta name="author" content="ムビラン">
    <meta name="language" content="ja">
    <link rel="canonical" href="{$canonical}">
    <title>{$title}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#2c3e50">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$description}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{$canonical}">
    <meta property="og:image" content="{$ogImage}">
    <meta property="og:locale" content="ja_JP">
    <meta property="og:site_name" content="MUBIRAN - 映画興行収入ランキング">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$title}">
    <meta name="twitter:description" content="{$description}">
    <meta name="twitter:image" content="{$ogImage}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Oswald:wght@400;700&display=swap" rel="stylesheet">
    {$styles}
    {$jsonLd}
  </head>
  <body>
    <div id="app" aria-live="polite">{$appHtml}</div>
    <script type="module" src="{$script}"></script>
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
          navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
      }
    </script>
  </body>
</html>
HTML;
    }
}
