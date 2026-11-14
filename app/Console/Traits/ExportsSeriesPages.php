<?php

namespace App\Console\Traits;

use App\Services\Series\JapanBoxOfficeAmount;
use App\Services\Series\JapanSeriesWikipediaParser;
use App\Services\Series\SeriesCatalog;
use Illuminate\Support\Facades\File;

trait ExportsSeriesPages
{
    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $otherCatalog
     */
    private function exportSeriesPages(string $output, array $catalog, array $otherCatalog): void
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $indexDir = $output.($isJapan ? '/series' : '/series/global');
        File::ensureDirectoryExists($indexDir);
        File::put($indexDir.'/index.html', $this->seriesIndexHtml($catalog, $otherCatalog));

        $jsonDir = $output.'/data/series/'.($isJapan ? 'japan' : 'global');
        File::ensureDirectoryExists($jsonDir);

        foreach ($catalog['series'] ?? [] as $series) {
            if (! is_array($series) || empty($series['slug'])) {
                continue;
            }
            $movies = $this->seriesMoviesForUi($series, $isJapan);
            File::put(
                $jsonDir.'/'.$series['slug'].'.json',
                json_encode(
                    $this->seriesClientPayload($series, $catalog, $movies),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
            $dir = $indexDir.'/'.$series['slug'];
            File::ensureDirectoryExists($dir);
            File::put($dir.'/index.html', $this->seriesDetailHtml($series, $catalog, $movies));
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return list<array{loc: string, lastmod: ?string, changefreq: string, priority: string}>
     */
    private function seriesSitemapEntries(array $catalog): array
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $lastmod = $this->seriesCatalogLastmod($catalog);
        $entries = [[
            'loc' => $baseUrl.($catalog['indexPath'] ?? '/series/'),
            'lastmod' => $lastmod,
            'changefreq' => 'weekly',
            'priority' => '0.7',
        ]];

        foreach ($catalog['series'] ?? [] as $series) {
            if (! is_array($series) || empty($series['path']) || empty($series['indexable'])) {
                continue;
            }
            $entries[] = [
                'loc' => $baseUrl.$series['path'],
                'lastmod' => $this->seriesLastmod($series, $lastmod),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function seriesCatalogLastmod(array $catalog): ?string
    {
        if (is_string($catalog['retrievedAt'] ?? null) && $catalog['retrievedAt'] !== '') {
            return $this->atomTimestamp($catalog['retrievedAt'].'T00:00:00+09:00')
                ?? $this->atomTimestamp($catalog['retrievedAt']);
        }

        $latest = null;
        foreach ($catalog['series'] ?? [] as $series) {
            $at = $this->seriesLastmod(is_array($series) ? $series : [], null);
            if ($at !== null && ($latest === null || strcmp($at, $latest) > 0)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function seriesLastmod(array $series, ?string $fallback): ?string
    {
        $latest = $fallback;
        foreach ($series['films'] ?? [] as $film) {
            if (! is_array($film)) {
                continue;
            }
            foreach ([$film['updatedAt'] ?? null, $film['releaseDate'] ?? null] as $raw) {
                $at = is_string($raw) ? $this->atomTimestamp($raw) : null;
                if ($at !== null && ($latest === null || strcmp($at, $latest) > 0)) {
                    $latest = $at;
                }
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $otherCatalog
     */
    private function seriesIndexHtml(array $catalog, array $otherCatalog): string
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $series = is_array($catalog['series'] ?? null) ? $catalog['series'] : [];
        $heading = $isJapan ? '日本の映画シリーズ興行収入' : '世界の映画シリーズ興行収入';
        $pageTitle = $heading.' | MUBIRAN';
        $description = $isJapan
            ? '日本で公開された映画シリーズの累計興行収入。名探偵コナン、ポケットモンスターなど、シリーズごとの作品一覧と興収合計。'
            : '世界興行収入をもとにした映画シリーズ（フランチャイズ）の累計。TMDBのコレクション情報から作品をまとめています。';
        if ($series !== []) {
            $top = $series[0];
            $topName = (string) ($top['name'] ?? '');
            $topTotal = $isJapan
                ? $this->yenLabel($top['totalYen'] ?? null)
                : $this->usdLabel($top['totalUsd'] ?? null);
            if ($topName !== '' && $topTotal !== '不明') {
                $description = $heading.'。1位は'.$topName.'の'.$topTotal.'。';
            }
        }

        $otherHref = $isJapan ? '/series/global/' : '/series/';
        $otherLabel = $isJapan ? '世界のシリーズ興行収入' : '日本のシリーズ興行収入';
        $rows = '';
        foreach ($series as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $href = $this->h((string) ($item['path'] ?? '#'));
            $name = $this->h((string) ($item['name'] ?? ''));
            $count = (int) ($item['filmCount'] ?? 0);
            $total = $isJapan
                ? $this->h($this->yenLabel($item['totalYen'] ?? null))
                : $this->h($this->usdLabel($item['totalUsd'] ?? null));
            $rows .= '<tr><td class="series-rank">'.($index + 1).'</td>'
                .'<td><a href="'.$href.'">'.$name.'</a></td>'
                .'<td class="series-num">'.$count.'</td>'
                .'<td class="series-num">'.$total.'</td></tr>'."\n";
        }

        $attribution = $isJapan ? $this->japanSeriesAttribution($catalog) : $this->worldSeriesAttribution();
        $body = '<p class="series-lead">'.$this->h($isJapan
            ? 'Wikipediaの「映画シリーズの興行成績」に掲載されたシリーズです。作品ごとの日本興収と、シリーズ合計を掲載しています。'
            : '世界興収ランキングの作品が属するTMDBコレクションです。コレクション内の全作品の世界興収を集計しています。'
        ).'</p>'
            .'<p class="ranking-static-switch"><a href="'.$this->h($otherHref).'">'.$this->h($otherLabel).'</a></p>'
            .'<table class="series-table"><thead><tr><th>順位</th><th>シリーズ</th><th>作品数</th><th>合計</th></tr></thead><tbody>'
            .$rows.'</tbody></table>'
            .$attribution;

        $jsonLd = $this->seriesIndexJsonLd($catalog, $heading);

        $crumb = $isJapan ? '日本のシリーズ' : '世界のシリーズ';

        return $this->seriesPageShell(
            $catalog['indexPath'] ?? ($isJapan ? '/series/' : '/series/global/'),
            $pageTitle,
            $description,
            $crumb,
            $heading,
            $body,
            $jsonLd,
            [],
            'index, follow',
        );
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>|null  $movies
     */
    private function seriesDetailHtml(array $series, array $catalog, ?array $movies = null): string
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $name = (string) ($series['name'] ?? '');
        $heading = $name.'のシリーズ興行収入';
        $totalLabel = $isJapan
            ? $this->yenLabel($series['totalYen'] ?? null)
            : $this->usdLabel($series['totalUsd'] ?? null);
        $pageTitle = $heading.' '.$totalLabel.' | MUBIRAN';
        $filmCount = (int) ($series['filmCount'] ?? count($series['films'] ?? []));
        $description = $name.'シリーズの'.($isJapan ? '日本' : '世界').'興行収入。'
            .$filmCount.'作品、合計'.$totalLabel.'。';
        $movies ??= $this->seriesMoviesForUi($series, $isJapan);
        $payload = $this->seriesClientPayload($series, $catalog, $movies);
        $indexHref = $isJapan ? '/series/' : '/series/global/';
        $otherHref = $isJapan ? '/series/global/' : '/series/';
        $otherLabel = $isJapan ? '世界のシリーズ興行収入' : '日本のシリーズ興行収入';
        $rankingHref = $isJapan ? '/japan/' : '/';
        $nowHref = $isJapan ? '/now/' : '/now/global/';
        $crumb = $isJapan ? '日本のシリーズ' : '世界のシリーズ';
        $robots = ! empty($series['indexable']) ? 'index, follow' : 'noindex, follow';
        $jsonSrc = '/data/series/'.($isJapan ? 'japan' : 'global').'/'.rawurlencode((string) ($series['slug'] ?? '')).'.json';

        $stats = '<dl class="series-stats">'
            .'<div><dt>作品数</dt><dd>'.$filmCount.'</dd></div>'
            .'<div><dt>シリーズ合計</dt><dd>'.$this->h($totalLabel).'</dd></div>';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $stats .= '<div><dt>配給収入の作品</dt><dd>'.$this->h($this->yenLabel($series['sumRentalsYen'] ?? null)).'</dd></div>';
        }
        $stats .= '</dl>';
        $metricNote = '';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $metricNote = '<p class="series-metric-note">古い作品は配給収入で記録されていることがあります。配給収入と興行収入は別の指標なので、合計には混ぜていません。</p>';
        }

        $top = array_values(array_filter($movies, fn (array $movie) => (int) ($movie['rank'] ?? 0) <= 3));
        $rest = array_values(array_filter($movies, fn (array $movie) => (int) ($movie['rank'] ?? 0) > 3));
        $cards = '';
        foreach ($top as $movie) {
            $cards .= $this->seriesMovieCardHtml($movie);
        }
        $rows = '';
        foreach ($rest as $movie) {
            $rows .= $this->seriesMovieRowHtml($movie, $isJapan);
        }
        $listHtml = $movies === []
            ? '<p class="static-empty text-center text-white">データがありません。</p>'
            : '<section class="top-rankings" id="series-top-rankings">'.$cards.'</section>'
                .'<section class="ranking-list" id="series-ranking-list">'.$rows.'</section>';

        $attribution = $isJapan ? $this->japanSeriesAttribution($catalog) : $this->worldSeriesAttribution();
        $jsonLd = $this->seriesDetailJsonLd($series, $catalog);
        $embedded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $footer = $this->siteFooterInnerHtml();
        $styles = $this->viteStyles();
        $script = $this->viteScript();
        $boot = $this->rankingFallbackGuard();

        $baseUrl = rtrim(config('app.url'), '/');
        $path = (string) ($series['path'] ?? $indexHref);
        $canonical = $baseUrl.$path;
        $ogImage = $baseUrl.'/images/android-chrome-512x512.png';

        $ePageTitle = $this->h($pageTitle);
        $eDescription = $this->h(mb_substr($description, 0, 120));
        $eCanonical = $this->h($canonical);
        $eOgImage = $this->h($ogImage);
        $eHeading = $this->h($heading);
        $eRobots = $this->h($robots);
        $eName = $this->h($name);
        $eCrumb = $this->h($crumb);
        $eIndex = $this->h($indexHref);
        $eOther = $this->h($otherHref);
        $eOtherLabel = $this->h($otherLabel);
        $eRanking = $this->h($rankingHref);
        $eNow = $this->h($nowHref);
        $eJsonSrc = $this->h($jsonSrc);
        $eRegion = $isJapan ? 'japan' : 'global';

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    {$boot}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$eDescription}">
    <meta name="robots" content="{$eRobots}">
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
    {$jsonLd}
  </head>
  <body class="series-detail-page" data-page="series" data-region="{$eRegion}">
    <div id="app" data-series-src="{$eJsonSrc}" data-region="{$eRegion}" aria-live="polite">
      <header>
        <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
        <div class="header-controls">
          <div class="toggle-container view-toggle" role="group" aria-label="表示切替">
            <a href="{$eRanking}" class="toggle-btn">歴代</a>
            <a href="{$eNow}" class="toggle-btn">公開中</a>
          </div>
          <div class="toggle-container region-toggle" role="group" aria-label="シリーズ切替">
            <a href="/series/global/" class="toggle-btn {$this->h($isJapan ? '' : 'active')}">世界</a>
            <a href="/series/" class="toggle-btn {$this->h($isJapan ? 'active' : '')}">日本</a>
          </div>
        </div>
      </header>
      <main class="container">
        <nav class="content-breadcrumb" aria-label="パンくずリスト">
          <a href="/">ホーム</a><span aria-hidden="true"> › </span>
          <a href="{$eIndex}">{$eCrumb}</a><span aria-hidden="true"> › </span>
          <span>{$eName}</span>
        </nav>
        <h1 class="page-title" id="page-title">{$eHeading}</h1>
        {$stats}
        {$metricNote}
        {$listHtml}
        {$attribution}
        <p class="content-back-link"><a href="{$eIndex}">シリーズ一覧に戻る</a> · <a href="{$eOther}">{$eOtherLabel}</a></p>
      </main>
      <footer>
        <div class="container">
          {$footer}
        </div>
      </footer>
    </div>
    <script type="application/json" id="series-data">{$embedded}</script>
    <script>
      document.addEventListener('click', function (event) {
        var node = event.target;
        if (node && node.nodeType !== 1) node = node.parentElement;
        if (!node || !node.closest) return;
        var link = node.closest('#app a[href^="/movies/"]');
        if (!link || event.button !== 0) return;
        event.preventDefault();
      }, true);
    </script>
    <script type="module" src="{$script}" onerror="document.documentElement.classList.remove('js')"></script>
  </body>
</html>
HTML;
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $movies
     * @return array<string, mixed>
     */
    private function seriesClientPayload(array $series, array $catalog, array $movies): array
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';

        return [
            'region' => $isJapan ? 'japan' : 'global',
            'name' => $series['name'] ?? '',
            'heading' => ($series['name'] ?? '').'のシリーズ興行収入',
            'path' => $series['path'] ?? '',
            'slug' => $series['slug'] ?? '',
            'indexPath' => $isJapan ? '/series/' : '/series/global/',
            'crumb' => $isJapan ? '日本のシリーズ' : '世界のシリーズ',
            'usdJpy' => $this->usdJpy(),
            'filmCount' => (int) ($series['filmCount'] ?? count($movies)),
            'totalLabel' => $isJapan
                ? $this->yenLabel($series['totalYen'] ?? null)
                : $this->usdLabel($series['totalUsd'] ?? null),
            'hasRentals' => ! empty($series['hasRentals']),
            'sumRentalsLabel' => ! empty($series['hasRentals'])
                ? $this->yenLabel($series['sumRentalsYen'] ?? null)
                : null,
            'movies' => $movies,
        ];
    }

    /**
     * @param  array<string, mixed>  $series
     * @return list<array<string, mixed>>
     */
    private function seriesMoviesForUi(array $series, bool $isJapan): array
    {
        $films = [];
        foreach ($series['films'] ?? [] as $film) {
            if (is_array($film)) {
                $films[] = $film;
            }
        }
        $films = SeriesCatalog::sortFilmsByRelease($films);
        $movies = [];
        foreach ($films as $index => $film) {
            $id = $this->seriesFilmId($film);
            $title = (string) ($film['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $boxOffice = is_numeric($film['boxOffice'] ?? null) ? (int) $film['boxOffice'] : 0;
            $known = $boxOffice > 0 && $boxOffice !== 1_000_000_000;
            $rankingPosition = ! empty($film['inRanking']) && is_numeric($film['rank'] ?? null)
                ? (int) $film['rank']
                : null;
            $revenue = $this->seriesFilmRevenue($film, $isJapan);
            $movies[] = [
                'id' => $id,
                'slug' => (string) ($film['slug'] ?? $id),
                'tmdbId' => is_numeric($film['tmdbId'] ?? null) ? (int) $film['tmdbId'] : null,
                'rank' => $index + 1,
                'rankingPosition' => $rankingPosition,
                'inRanking' => $rankingPosition !== null,
                'title' => $title,
                'originalTitle' => (string) ($film['originalTitle'] ?? ''),
                'releaseDate' => $film['releaseDate'] ?? null,
                'releaseYear' => $film['releaseYear'] ?? null,
                'genres' => is_array($film['genres'] ?? null) ? $film['genres'] : [],
                'posterUrl' => (string) ($film['posterUrl'] ?? ''),
                'isActive' => (bool) ($film['isActive'] ?? false),
                'boxOffice' => $known ? $boxOffice : 0,
                'revenueBillion' => $known && $isJapan
                    ? number_format($boxOffice / 100000000, 1)
                    : ($known ? ($film['revenueBillion'] ?? null) : null),
                'productionCountry' => $isJapan ? (string) ($film['productionCountry'] ?? '日本') : ($film['productionCountry'] ?? null),
                'revenue' => $revenue,
                'revenueYen' => $known && ! $isJapan ? ($film['revenueYen'] ?? null) : null,
                'href' => (string) ($film['href'] ?? '/movies/'.$id.'/'),
                'metric' => (string) ($film['metric'] ?? 'box_office'),
            ];
        }

        return $movies;
    }

    /**
     * @param  array<string, mixed>  $film
     */
    private function seriesFilmId(array $film): string
    {
        $id = trim((string) ($film['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }
        $href = (string) ($film['href'] ?? '');
        if (preg_match('~/movies/([^/]+)/?$~', $href, $matches)) {
            return rawurldecode($matches[1]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $film
     */
    private function seriesFilmRevenue(array $film, bool $isJapan): string
    {
        $boxOffice = $film['boxOffice'] ?? null;
        if (! is_numeric($boxOffice) || (int) $boxOffice <= 0 || (int) $boxOffice === 1_000_000_000) {
            return '不明';
        }
        $label = is_string($film['revenue'] ?? null) && $film['revenue'] !== '' && $film['revenue'] !== '不明'
            ? (string) $film['revenue']
            : ($isJapan ? $this->yenLabel($boxOffice) : $this->usdLabel($boxOffice));
        if (
            ($film['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS
            && ! str_contains($label, '配給収入')
        ) {
            $label .= '（配給収入）';
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesMovieCardHtml(array $movie): string
    {
        $url = (string) ($movie['posterUrl'] ?? '');
        $posterClass = $url !== '' ? 'card-poster' : 'card-poster poster-placeholder';
        $posterStyle = $url !== '' ? ' style="background-image:url(\''.$this->h($url).'\')"' : '';
        $bg = $url !== '' ? '<div class="card-bg-image" style="background-image:url(\''.$this->h($url).'\')"></div>' : '';
        $rank = (int) ($movie['rank'] ?? 0);
        $id = $this->h((string) ($movie['id'] ?? ''));
        $title = (string) ($movie['title'] ?? '');
        $href = $this->h((string) ($movie['href'] ?? '/movies/'.$movie['id'].'/'));
        $titleClass = $this->seriesTitleClass($title);
        $chip = $this->seriesRankingChipHtml($movie);
        $active = ! empty($movie['isActive']) ? ' active-movie' : '';
        $badge = ! empty($movie['isActive']) ? '<div class="active-badge-card">公開中</div>' : '';
        $subtitle = $this->seriesOriginalTitleHtml($movie, 'div');
        $yen = ! empty($movie['revenueYen'])
            ? '<div class="revenue-sub">'.$this->h((string) $movie['revenueYen']).'</div>'
            : '';

        return '<article class="top-card rank-'.$rank.$active.'" data-movie-id="'.$id.'" role="button" tabindex="0" aria-label="'.$this->h($title).'の詳細を見る">'
            .$badge
            .'<div class="rank-badge">'.$rank.'</div>'
            .'<div class="'.$posterClass.'"'.$posterStyle.'></div>'
            .$bg
            .'<div class="title-line"><a class="movie-title '.$titleClass.'" href="'.$href.'">'.$this->h($title).'</a>'.$chip.'</div>'
            .$subtitle
            .'<div class="revenue-container"><div class="revenue-main" style="color: var(--accent-gold);">'.$this->h((string) ($movie['revenue'] ?? '不明')).'</div>'.$yen.'</div>'
            .'</article>';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesMovieRowHtml(array $movie, bool $isJapan): string
    {
        $url = (string) ($movie['posterUrl'] ?? '');
        $bg = $url !== '' ? '<div class="list-bg-image" style="background-image:url(\''.$this->h($url).'\')"></div>' : '';
        $rank = (int) ($movie['rank'] ?? 0);
        $id = $this->h((string) ($movie['id'] ?? ''));
        $title = (string) ($movie['title'] ?? '');
        $href = $this->h((string) ($movie['href'] ?? '/movies/'.$movie['id'].'/'));
        $titleClass = $this->seriesTitleClass($title);
        $chip = $this->seriesRankingChipHtml($movie);
        $active = ! empty($movie['isActive']) ? ' active-movie' : '';
        $japan = $isJapan ? ' is-japan' : '';
        $badge = ! empty($movie['isActive']) ? '<div class="active-badge-card">公開中</div>' : '';
        $subtitle = $this->seriesOriginalTitleHtml($movie, 'span');
        $yen = ! empty($movie['revenueYen'])
            ? '<span class="revenue-sub">'.$this->h((string) $movie['revenueYen']).'</span>'
            : '';
        $boxOffice = (int) ($movie['boxOffice'] ?? 0);
        $max = $isJapan ? 150 : 3;
        $width = min(($boxOffice / 100000000) / $max * 100, 100);

        return '<article class="list-item'.$active.$japan.'" data-movie-id="'.$id.'" role="button" tabindex="0" aria-label="'.$this->h($title).'の詳細を見る">'
            .$badge
            .'<div class="revenue-bar-bg" style="width: '.$width.'%;"></div>'
            .$bg
            .'<div class="list-rank">'.$rank.'</div>'
            .'<div class="list-info"><div class="title-line"><a class="movie-title '.$titleClass.'" href="'.$href.'">'.$this->h($title).'</a>'.$chip.'</div>'.$subtitle.'</div>'
            .'<div class="list-revenue"><span class="revenue-main">'.$this->h((string) ($movie['revenue'] ?? '不明')).'</span>'.$yen.'</div>'
            .'</article>';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesRankingChipHtml(array $movie): string
    {
        if (! is_numeric($movie['rankingPosition'] ?? null)) {
            return '';
        }
        $href = $this->h((string) ($movie['href'] ?? '/movies/'.$movie['id'].'/'));
        $rank = (string) (int) $movie['rankingPosition'];

        return '<a class="series-ranking-chip" href="'.$href.'" title="歴代ランキング">'.$this->h($rank).'</a>';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesOriginalTitleHtml(array $movie, string $tag): string
    {
        $original = trim((string) ($movie['originalTitle'] ?? ''));
        $title = (string) ($movie['title'] ?? '');
        $country = (string) ($movie['productionCountry'] ?? '');
        if ($original === '' || $original === $title || $country === 'JP' || $country === '日本') {
            return '';
        }
        $class = $tag === 'div' ? 'movie-title-en' : 'movie-title-en';

        return '<'.$tag.' class="'.$class.'">'.$this->h($original).'</'.$tag.'>';
    }

    private function seriesTitleClass(string $title): string
    {
        $length = mb_strlen($title);
        if ($length > 35) {
            return 'title-long';
        }
        if ($length > 20) {
            return 'title-medium';
        }

        return 'title-short';
    }

    /**
     * @param  list<array{href: string, name: string}>  $middleCrumbs
     */
    private function seriesPageShell(
        string $path,
        string $pageTitle,
        string $metaDescription,
        string $breadcrumbLabel,
        string $heading,
        string $bodyHtml,
        string $jsonLd,
        array $middleCrumbs = [],
        string $robots = 'index, follow',
    ): string {
        $baseUrl = rtrim(config('app.url'), '/');
        $canonical = $baseUrl.$path;
        $ogImage = $baseUrl.'/images/android-chrome-512x512.png';
        $styles = $this->viteStyles();
        $footer = $this->siteFooterInnerHtml();
        $crumbs = '<a href="/">ホーム</a><span aria-hidden="true"> › </span>';
        foreach ($middleCrumbs as $crumb) {
            $crumbs .= '<a href="'.$this->h($crumb['href']).'">'.$this->h($crumb['name']).'</a><span aria-hidden="true"> › </span>';
        }
        $crumbs .= '<span>'.$this->h($breadcrumbLabel).'</span>';

        $ePageTitle = $this->h($pageTitle);
        $eDescription = $this->h(mb_substr($metaDescription, 0, 120));
        $eCanonical = $this->h($canonical);
        $eOgImage = $this->h($ogImage);
        $eHeading = $this->h($heading);
        $eRobots = $this->h($robots);

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$eDescription}">
    <meta name="robots" content="{$eRobots}">
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
    {$jsonLd}
  </head>
  <body class="content-page series-page">
    <header>
      <a href="/" class="logo-link" aria-label="MUBIRAN トップ"><img src="/images/logo.png" alt="MUBIRAN" class="logo-img"></a>
    </header>
    <main class="container content-page-main">
      <nav class="content-breadcrumb" aria-label="パンくずリスト">
        {$crumbs}
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

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function seriesIndexJsonLd(array $catalog, string $name): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $path = $catalog['indexPath'] ?? '/series/';
        $elements = [];
        foreach ($catalog['series'] ?? [] as $offset => $series) {
            if (! is_array($series) || empty($series['path']) || empty($series['name'])) {
                continue;
            }
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $offset + 1,
                'name' => $series['name'],
                'url' => $baseUrl.$series['path'],
            ];
        }

        $crumb = ($catalog['region'] ?? '') === 'japan' ? '日本のシリーズ' : '世界のシリーズ';
        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $baseUrl.'/', 'name' => 'ホーム']],
                        ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $baseUrl.$path, 'name' => $crumb]],
                    ],
                ],
                [
                    '@type' => 'ItemList',
                    'name' => $name,
                    'itemListElement' => $elements,
                ],
            ],
        ];

        return '<script type="application/ld+json">'.json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     */
    private function seriesDetailJsonLd(array $series, array $catalog): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $indexPath = $catalog['indexPath'] ?? '/series/';
        $path = (string) ($series['path'] ?? $indexPath);
        $name = (string) ($series['name'] ?? '');
        $elements = [];
        foreach ($series['films'] ?? [] as $offset => $film) {
            if (! is_array($film) || ($film['title'] ?? '') === '') {
                continue;
            }
            $item = [
                '@type' => 'ListItem',
                'position' => $offset + 1,
                'name' => $film['title'],
            ];
            if (! empty($film['href'])) {
                $item['url'] = $baseUrl.$film['href'];
            }
            $elements[] = $item;
        }

        $indexName = ($catalog['region'] ?? '') === 'japan' ? '日本のシリーズ' : '世界のシリーズ';
        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $baseUrl.'/', 'name' => 'ホーム']],
                        ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $baseUrl.$indexPath, 'name' => $indexName]],
                        ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $baseUrl.$path, 'name' => $name]],
                    ],
                ],
                [
                    '@type' => 'ItemList',
                    'name' => $name.'のシリーズ興行収入',
                    'itemListElement' => $elements,
                ],
            ],
        ];

        return '<script type="application/ld+json">'.json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function japanSeriesAttribution(array $catalog): string
    {
        $wikiUrl = $this->h((string) ($catalog['sourceUrl'] ?? JapanSeriesWikipediaParser::SOURCE_URL));
        $ccUrl = $this->h((string) ($catalog['licenseUrl'] ?? 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja'));
        $retrieved = is_string($catalog['retrievedAt'] ?? null) ? $this->h($catalog['retrievedAt']) : '';
        $date = $retrieved !== '' ? '（取得日 '.$retrieved.'）' : '';

        return '<p class="series-attr">日本のシリーズ興行収入は Wikipedia『<a href="'.$wikiUrl.'" target="_blank" rel="noreferrer">日本歴代興行成績上位の映画一覧</a>』の「映画シリーズの興行成績」'.$date.'を出典としています（<a href="'.$ccUrl.'" target="_blank" rel="noreferrer">CC BY-SA 4.0</a>）。数値が取れない作品は不明と表示しています。</p>';
    }

    private function worldSeriesAttribution(): string
    {
        return '<p class="series-attr">世界のシリーズ区分と興行収入は <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDB</a> のコレクション（belongs_to_collection）と作品の revenue を出典としています。</p>';
    }

    private function yenLabel(mixed $yen): string
    {
        if (! is_numeric($yen) || (int) $yen <= 0) {
            return '不明';
        }

        return number_format(((int) $yen) / 100000000, 1).'億円';
    }

    private function usdLabel(mixed $usd): string
    {
        if (! is_numeric($usd) || (int) $usd <= 0) {
            return '不明';
        }

        return number_format(((int) $usd) / 100000000, 2).'億ドル';
    }
}
