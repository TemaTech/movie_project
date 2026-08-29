<?php

namespace App\Console\Commands;

use App\Console\Traits\FetchesTmdbMovieDetails;
use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\BoxOffice\Insights;
use App\Services\BoxOffice\MovieIdentity;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportStaticSite extends Command
{
    use FetchesTmdbMovieDetails;

    protected $signature = 'site:export-static
                            {--output=dist : Export directory}
                            {--refresh-details : Re-fetch all TMDb detail files}';

    protected $description = 'Export the movie rankings as a static site for Cloudflare Pages';

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

        File::put($output . '/data/movies.json', json_encode([
            'generatedAt' => now('Asia/Tokyo')->toIso8601String(),
            'usdJpy' => $this->usdJpy(),
            'globalLastUpdated' => $this->maxLastUpdated(GlobalMovie::class),
            'japanLastUpdated' => $this->maxLastUpdated(JapaneseMovie::class),
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
        $this->exportSitemap($output, $global, $japan, $boardOnly);
        File::put($output . '/index.html', $this->indexHtml());

        $moviePageCount = count($global) + count($japan) + count($boardOnly);
        $this->info("Static site exported to {$output}");
        $this->line("Global: ".count($global)." movies / Japan: ".count($japan)." movies");
        $this->line("Movie pages: {$moviePageCount}");

        return self::SUCCESS;
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

    private function exportRobotsTxt(string $output): void
    {
        $baseUrl = rtrim(config('app.url'), '/');

        File::put($output . '/robots.txt', <<<TXT
User-agent: *
Allow: /

# サイトマップ
Sitemap: {$baseUrl}/sitemap.xml

# 検索エンジンにクロールしてほしくないディレクトリ
Disallow: /storage/
Disallow: /data/

# クロール間隔の設定（サーバー負荷軽減）
Crawl-delay: 1
TXT);
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
            $history->observations()->loadByKey($region),
            $registry->movies(),
            now('Asia/Tokyo')->toDateTimeImmutable(),
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
                'sparkline' => $insight['sparkline'] ?? [],
                'milestones' => $insight['milestones'] ?? [],
                'nextMilestone' => $insight['nextMilestone'] ?? null,
                'nextToOvertake' => $insight['nextToOvertake'] ?? null,
                'periodGrowth' => $insight['periodGrowth'] ?? [],
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
                        'sparkline' => $item['sparkline'] ?? [],
                        'milestones' => $item['milestones'] ?? [],
                        'nextMilestone' => $item['nextMilestone'] ?? null,
                        'nextToOvertake' => $item['nextToOvertake'] ?? null,
                        'periodGrowth' => $item['periodGrowth'] ?? [],
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

        $chart = $this->trajectorySvg($history['sparkline'] ?? [], $isJapan);
        if ($chart !== '') {
            $sections[] = '<section class="movie-section movie-trajectory"><h2>興収の推移</h2>'.$chart.'</section>';
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
     * @param  list<array{at?: string, boxOffice?: int}>  $points
     */
    private function trajectorySvg(array $points, bool $isJapan): string
    {
        $rows = [];
        foreach ($points as $point) {
            if (! is_array($point) || ! isset($point['boxOffice'], $point['at'])) {
                continue;
            }
            $rows[] = [
                'at' => (string) $point['at'],
                'boxOffice' => (int) $point['boxOffice'],
            ];
        }
        if (count($rows) < 2) {
            return '';
        }

        $values = array_column($rows, 'boxOffice');
        $min = min($values);
        $max = max($values);
        if ($min === $max) {
            return '';
        }

        $width = 640;
        $height = 168;
        $padL = 58;
        $padR = 16;
        $padT = 18;
        $padB = 36;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;
        $span = $max - $min;
        $count = count($rows);

        $coords = [];
        foreach ($values as $index => $value) {
            $x = $padL + ($index / ($count - 1)) * $plotW;
            $y = $padT + (1 - (($value - $min) / $span)) * $plotH;
            $coords[] = sprintf('%.1f,%.1f', $x, $y);
        }

        $guides = $isJapan
            ? [5_000_000_000, 10_000_000_000]
            : [500_000_000, 1_000_000_000];
        $guideSvg = '';
        foreach ($guides as $threshold) {
            if ($threshold < $min || $threshold > $max) {
                continue;
            }
            $y = $padT + (1 - (($threshold - $min) / $span)) * $plotH;
            $label = Insights::formatAmount($threshold, $isJapan);
            $x2 = $padL + $plotW;
            $guideSvg .= sprintf(
                '<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" class="movie-chart-guide" />',
                $padL,
                $y,
                $x2,
                $y,
            );
            $guideSvg .= '<text x="'.sprintf('%.1f', $x2 - 4).'" y="'.sprintf('%.1f', $y - 5).'" text-anchor="end" class="movie-chart-guide-label">'.$this->h($label).'</text>';
        }

        $unit = $isJapan ? '億円' : '億ドル';
        $decimals = $isJapan ? 1 : 2;
        $minLabel = number_format($min / 100_000_000, $decimals);
        $maxLabel = number_format($max / 100_000_000, $decimals);
        $firstDate = $this->h($this->chartDateLabel($rows[0]['at']));
        $lastDate = $this->h($this->chartDateLabel($rows[$count - 1]['at']));
        $polyline = implode(' ', $coords);
        $yMinText = $padT + $plotH;
        $xLast = $padL + $plotW;
        $dateY = $height - 6;

        return <<<HTML
<svg class="movie-chart" viewBox="0 0 {$width} {$height}" role="img" aria-label="興行収入の推移（{$unit}）">
  <text x="8" y="{$padT}" class="movie-chart-axis">{$maxLabel}</text>
  <text x="8" y="{$yMinText}" class="movie-chart-axis">{$minLabel}</text>
  {$guideSvg}
  <polyline class="movie-chart-line" points="{$polyline}" />
  <text x="{$padL}" y="{$dateY}" class="movie-chart-axis">{$firstDate}</text>
  <text x="{$xLast}" y="{$dateY}" text-anchor="end" class="movie-chart-axis">{$lastDate}</text>
</svg>
<p class="movie-chart-caption">横軸: 観測日 / 縦軸: {$unit}（発表ベース）</p>
HTML;
    }

    private function chartDateLabel(string $iso): string
    {
        try {
            return Carbon::parse($iso)->timezone('Asia/Tokyo')->format('n/j');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function exportMoviePages(string $output, $global, $japan): void
    {
        foreach ($global as $movie) {
            $this->writeMoviePage($output, $movie, false);
        }

        foreach ($japan as $movie) {
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
                'lastmod' => $defaultLastmod,
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
        $homeUrl = $baseUrl . '/?tab=' . ($isJapan ? 'japan' : 'global');

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

        $cast = array_slice($detail['credits']['cast'] ?? [], 0, 6);
        $castHtml = '';
        if ($cast) {
            $castHtml = '<h3>主要キャスト</h3><div class="movie-cast">';
            foreach ($cast as $actor) {
                $castHtml .= '<div class="cast-item"><span class="cast-name">' . $this->h($actor['name'] ?? '') . '</span></div>';
            }
            $castHtml .= '</div>';
        }

        $overviewHtml = $overview
            ? '<h3>あらすじ</h3><p>' . nl2br($this->h($overview)) . '</p>'
            : '';
        $infoHtml = ($overviewHtml !== '' || $castHtml !== '')
            ? '<section class="movie-section movie-info-secondary"><h2>作品情報</h2>'.$overviewHtml.$castHtml.'</section>'
            : '';

        $subtitle = ($originalTitle && $originalTitle !== $title)
            ? '<p class="movie-original-title">' . $this->h($originalTitle) . '</p>'
            : '';

        $posterHtml = $movie['posterUrl']
            ? '<img src="' . $this->h($this->absoluteUrl($movie['posterUrl'])) . '" alt="' . $this->h($title) . '" class="movie-poster" width="160" height="240" loading="lazy">'
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
            {$yenRow}
            <div><dt>公開日</dt><dd>{$eReleaseDate}</dd></div>
          </dl>
        </div>
        {$historyHtml}
        {$infoHtml}
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

    private function indexHtml(string $path = '/'): string
    {
        $styles = $this->viteStyles();
        $script = $this->viteScript();

        $baseUrl = rtrim(config('app.url'), '/');
        $canonical = $baseUrl.($path === '/' ? '/' : rtrim($path, '/').'/');
        $isNow = str_starts_with($path, '/now');
        $isNowGlobal = $path === '/now/global/';
        if ($isNowGlobal) {
            $title = '公開中映画の興行収入の勢い（世界） | MUBIRAN';
            $description = '世界で公開中の映画がどれくらいのペースで興行収入を伸ばしているかを追跡。前回発表からの伸び、1日あたりのペース、マイルストーン到達を毎日確認できます。';
        } elseif ($isNow) {
            $title = '公開中映画の興行収入の勢い（日本） | MUBIRAN';
            $description = '日本で公開中の映画がどれくらいのペースで興行収入を伸ばしているかを追跡。前回発表からの伸び、1日あたりのペース、マイルストーン到達を毎日確認できます。';
        } else {
            $title = '歴代映画興行収入ランキング | 世界・日本のヒット作を徹底分析 - MUBIRAN';
            $description = '「アバター」「鬼滅の刃」など、世界と日本の歴代ヒット映画の興行収入ランキングを完全網羅。興収だけでなく制作費や利益率まで可視化。あなたの好きな映画は今何位？最新データを毎日更新。';
        }
        $keywords = '映画,興行収入,ランキング,売上,日本映画,世界の映画,最新,ムビラン,ボックスオフィス,映画統計,映画データ,映画売上,興行成績,映画ランキング,最新映画,公開中';
        $ogImage = $baseUrl . '/images/android-chrome-512x512.png';

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
  </head>
  <body>
    <div id="app" aria-live="polite"></div>
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
