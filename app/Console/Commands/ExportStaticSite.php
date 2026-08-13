<?php

namespace App\Console\Commands;

use App\Console\Traits\FetchesTmdbMovieDetails;
use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
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
            ->map(fn (GlobalMovie $movie, int $index) => $this->movieData($movie, $index + 1, false));
        $japan = $japanModels->values()
            ->map(fn (JapaneseMovie $movie, int $index) => $this->movieData($movie, $index + 1, true));

        File::put($output . '/data/movies.json', json_encode([
            'generatedAt' => now('Asia/Tokyo')->toIso8601String(),
            'globalLastUpdated' => $this->maxLastUpdated(GlobalMovie::class),
            'japanLastUpdated' => $this->maxLastUpdated(JapaneseMovie::class),
            'global' => $global,
            'japan' => $japan,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->copyPublicAssets($output);
        $this->exportRobotsTxt($output);
        $this->exportMoviePages($output, $global, $japan);
        $this->exportTrustPages($output);
        $this->exportSitemap($output, $globalModels, $japanModels);
        File::put($output . '/index.html', $this->indexHtml());

        $moviePageCount = $global->count() + $japan->count();
        $this->info("Static site exported to {$output}");
        $this->line("Global: {$global->count()} movies / Japan: {$japan->count()} movies");
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
        $isActive = $isJapan
            ? (bool) $movie->is_active
            : ($releaseDate && Carbon::parse($releaseDate)->greaterThanOrEqualTo(now()->subMonths(6)));

        $revenueBillion = $isJapan
            ? number_format($movie->box_office / 100000000, 1)
            : number_format($movie->box_office * 150 / 100000000, 1);

        $dbTitle = $movie->title;
        $title = $this->titleMap()[$dbTitle] ?? $dbTitle;

        return [
            'id' => $movie->movie_id,
            'tmdbId' => $movie->tmdb_id,
            'rank' => $rank,
            'title' => $title,
            'originalTitle' => $movie->original_title,
            'releaseDate' => $releaseDate,
            'releaseYear' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
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
            'revenueYen' => $isJapan ? null : number_format($movie->box_office * 150 / 100000000, 1) . '億円',
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

    private function movieSlug(string $movieId): string
    {
        if (str_starts_with($movieId, 'global_')) {
            return str_replace('global_', '', $movieId);
        }

        return $movieId;
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

    private function exportSitemap(string $output, $globalModels, $japanModels): void
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $defaultLastmod = now('Asia/Tokyo')->toAtomString();

        $urls = [[
            'loc' => "{$baseUrl}/",
            'lastmod' => $defaultLastmod,
            'changefreq' => 'daily',
            'priority' => '1.0',
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

        foreach ($globalModels as $movie) {
            if (! str_starts_with($movie->movie_id, 'global_')) {
                continue;
            }

            $urls[] = [
                'loc' => "{$baseUrl}/movies/" . $this->movieSlug($movie->movie_id),
                'lastmod' => $movie->last_updated
                    ? Carbon::parse($movie->last_updated)->toAtomString()
                    : $defaultLastmod,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ];
        }

        foreach ($japanModels as $movie) {
            if (! str_starts_with($movie->movie_id, 'jp_')) {
                continue;
            }

            $urls[] = [
                'loc' => "{$baseUrl}/movies/" . $movie->movie_id,
                'lastmod' => $movie->last_updated
                    ? Carbon::parse($movie->last_updated)->toAtomString()
                    : $defaultLastmod,
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
        $pageUrl = "{$baseUrl}/movies/{$slug}";
        $homeUrl = $baseUrl . '/?tab=' . ($isJapan ? 'japan' : 'global');

        $title = $movie['title'];
        $originalTitle = $detail['original_title'] ?? $movie['originalTitle'] ?? '';
        $overview = $detail['overview'] ?? '';
        $releaseDate = $detail['release_date'] ?? $movie['releaseDate'] ?? null;
        $rankLabel = $isJapan ? '日本ランキング' : '世界ランキング';
        $revenueText = $movie['revenue'] . ($movie['revenueYen'] ? "（{$movie['revenueYen']}）" : '');

        $pageTitle = "{$title} の興行収入 {$movie['revenue']} | {$rankLabel}第{$movie['rank']}位 - MUBIRAN";
        $description = "{$title}の興行収入は{$revenueText}（{$rankLabel}第{$movie['rank']}位）。";
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

        $director = collect($detail['credits']['crew'] ?? [])
            ->first(fn (array $member) => ($member['job'] ?? '') === 'Director');
        $directorName = $director['name'] ?? '-';

        $genres = ! empty($detail['genres'])
            ? collect($detail['genres'])->pluck('name')->all()
            : ($movie['genres'] ?? []);
        $genreHtml = $genres
            ? '<div class="movie-genres">' . collect($genres)
                ->map(fn (string $genre) => '<span class="genre-tag">' . $this->h($genre) . '</span>')
                ->implode('') . '</div>'
            : '';

        $cast = array_slice($detail['credits']['cast'] ?? [], 0, 6);
        $castHtml = '';
        if ($cast) {
            $castHtml = '<section class="movie-section"><h2>主要キャスト</h2><div class="movie-cast">';
            foreach ($cast as $actor) {
                $castHtml .= '<div class="cast-item"><span class="cast-name">' . $this->h($actor['name'] ?? '') . '</span></div>';
            }
            $castHtml .= '</div></section>';
        }

        $runtime = ! empty($detail['runtime']) ? $detail['runtime'] . '分' : '-';
        $rating = ! empty($detail['vote_average']) ? '★ ' . number_format((float) $detail['vote_average'], 1) : '-';
        $tagline = $detail['tagline'] ?? '';
        $overviewHtml = $overview
            ? '<p>' . nl2br($this->h($overview)) . '</p>'
            : '<p>あらすじ情報は現在ありません。</p>';

        $subtitle = ($originalTitle && $originalTitle !== $title)
            ? '<p class="movie-original-title">' . $this->h($originalTitle) . '</p>'
            : '';

        $posterHtml = $movie['posterUrl']
            ? '<img src="' . $this->h($this->absoluteUrl($movie['posterUrl'])) . '" alt="' . $this->h($title) . '" class="movie-poster" width="342" height="513" loading="lazy">'
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
        $eRevenue = $this->h($movie['revenue']);
        $eRevenueYen = $this->h($isJapan ? ($movie['revenue'] ?? '-') : ($movie['revenueYen'] ?? '-'));
        $yenRow = $isJapan ? '' : "<div><dt>日本換算</dt><dd>{$eRevenueYen}</dd></div>\n            ";
        $eReleaseDate = $this->h($this->formatReleaseDate($releaseDate));
        $eRuntime = $this->h($runtime);
        $eRating = $this->h($rating);
        $eDirectorName = $this->h($directorName);
        $eTagline = $this->h($tagline);
        $eHomeUrl = $this->h($homeUrl);
        $footerInner = $this->siteFooterInnerHtml();

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
        <h1 class="movie-page-title">{$eTitle}</h1>
        {$subtitle}
        <div class="movie-hero">
          {$posterHtml}
          <dl class="movie-stats">
            <div><dt>{$eRankLabel}</dt><dd>第{$movie['rank']}位</dd></div>
            <div><dt>興行収入</dt><dd>{$eRevenue}</dd></div>
            {$yenRow}
            <div><dt>公開日</dt><dd>{$eReleaseDate}</dd></div>
            <div><dt>上映時間</dt><dd>{$eRuntime}</dd></div>
            <div><dt>評価</dt><dd>{$eRating}</dd></div>
            <div><dt>監督</dt><dd>{$eDirectorName}</dd></div>
          </dl>
        </div>
        <p class="movie-tagline">{$eTagline}</p>
        {$genreHtml}
        <section class="movie-section">
          <h2>あらすじ</h2>
          {$overviewHtml}
        </section>
        {$castHtml}
      </article>
      <p class="movie-back-link"><a href="{$eHomeUrl}">{$eRankLabel}一覧に戻る</a></p>
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

        return <<<HTML
        <p class="site-footer-links"><a href="/about/">このサイトについて</a> · <a href="/privacy/">プライバシーポリシー</a></p>
        <p>&copy; {$year} MUBIRAN. All rights reserved.</p>
        <p>Data provided by <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb</a> and <a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a>.</p>
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
        $body = <<<'HTML'
        <section class="content-section">
          <h2>MUBIRAN（ムビラン）とは</h2>
          <p>MUBIRANは、世界と日本の映画興行収入ランキングをわかりやすく掲載する情報サイトです。歴代ヒット作の興行成績を比較し、作品ごとの詳細情報も確認できます。</p>
        </section>
        <section class="content-section">
          <h2>掲載データについて</h2>
          <p>興行収入・作品情報は、主に以下の公開データをもとに整理・掲載しています。</p>
          <ul>
            <li><a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDb（The Movie Database）</a></li>
            <li><a href="https://ja.wikipedia.org/" target="_blank" rel="noreferrer">Wikipedia</a></li>
          </ul>
          <p>日本映画については、公開情報や各種データソースを参照しています。表示内容は参考情報であり、公式の興行成績と異なる場合があります。</p>
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
          <p>掲載内容の修正依頼やお問い合わせは、<a href="mailto:horiuchi.cadd9@gmail.com">horiuchi.cadd9@gmail.com</a> までご連絡ください。</p>
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
          <p>当サイトでは、表示の利便性向上のため Service Worker を利用することがあります。ブラウザに保存されるデータは、サイトの高速表示やオフライン対応を目的としたものであり、広告配信や第三者への販売には使用しません。</p>
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
          <p>本ポリシーに関するお問い合わせは、<a href="mailto:horiuchi.cadd9@gmail.com">horiuchi.cadd9@gmail.com</a> までご連絡ください。</p>
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

    private function indexHtml(): string
    {
        $styles = $this->viteStyles();
        $script = $this->viteScript();

        $baseUrl = rtrim(config('app.url'), '/');
        $title = '歴代映画興行収入ランキング | 世界・日本のヒット作を徹底分析 - MUBIRAN';
        $description = '「アバター」「鬼滅の刃」など、世界と日本の歴代ヒット映画の興行収入ランキングを完全網羅。興収だけでなく制作費や利益率まで可視化。あなたの好きな映画は今何位？最新データを毎日更新。';
        $keywords = '映画,興行収入,ランキング,売上,日本映画,世界の映画,最新,ムビラン,ボックスオフィス,映画統計,映画データ,映画売上,興行成績,映画ランキング,最新映画';
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
    <link rel="canonical" href="{$baseUrl}/">
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
    <meta property="og:url" content="{$baseUrl}/">
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
