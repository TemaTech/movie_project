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
     * @param  array<string, mixed>  $brandCatalog
     */
    private function exportSeriesPages(string $output, array $catalog, array $otherCatalog, array $brandCatalog = []): void
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $indexDir = $output.($isJapan ? '/series' : '/series/global');
        File::ensureDirectoryExists($indexDir);
        File::put($indexDir.'/index.html', $this->seriesIndexHtml($catalog, $otherCatalog, $brandCatalog));

        $jsonDir = $output.'/data/series/'.($isJapan ? 'japan' : 'global');
        File::ensureDirectoryExists($jsonDir);

        foreach ($catalog['series'] ?? [] as $series) {
            if (! is_array($series) || empty($series['slug']) || ! SeriesCatalog::hasListedFilms($series)) {
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
            if (! is_array($series) || empty($series['path']) || empty($series['indexable']) || ! SeriesCatalog::hasListedFilms($series)) {
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
     * @param  array<string, mixed>  $otherCatalog
     */
    private function exportBrandPages(string $output, array $catalog, array $otherCatalog): void
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $indexDir = $output.($isJapan ? '/series/brand' : '/series/global/brand');
        File::ensureDirectoryExists($indexDir);
        File::put($indexDir.'/index.html', $this->brandIndexHtml($catalog, $otherCatalog));

        $jsonDir = $output.'/data/series/'.($isJapan ? 'japan' : 'global').'/brand';
        File::ensureDirectoryExists($jsonDir);

        foreach ($catalog['series'] ?? [] as $brand) {
            if (! is_array($brand) || empty($brand['slug']) || ! SeriesCatalog::hasListedFilms($brand)) {
                continue;
            }
            $page = $this->withBrandSibling($brand, $otherCatalog);
            $movies = $this->seriesMoviesForUi($page, $isJapan);
            File::put(
                $jsonDir.'/'.$page['slug'].'.json',
                json_encode(
                    $this->seriesClientPayload($page, $catalog, $movies),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
            $dir = $indexDir.'/'.$page['slug'];
            File::ensureDirectoryExists($dir);
            File::put($dir.'/index.html', $this->seriesDetailHtml($page, $catalog, $movies));
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function seriesCatalogLastmod(array $catalog): ?string
    {
        if (is_string($catalog['retrievedAt'] ?? null) && $catalog['retrievedAt'] !== '') {
            return $this->clampAtomTimestamp(
                $this->atomTimestamp($catalog['retrievedAt'].'T00:00:00+09:00')
                    ?? $this->atomTimestamp($catalog['retrievedAt'])
            );
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
     * シリーズの lastmod / dateModified。公開日は使わず、作品の興収データ時点かデータセット更新日。
     * 未公開作品は含めず、ビルド時点より後にはしない。
     *
     * @param  array<string, mixed>  $series
     */
    private function seriesLastmod(array $series, ?string $fallback): ?string
    {
        $latest = null;
        foreach ($series['films'] ?? [] as $film) {
            if (! is_array($film) || SeriesCatalog::filmIsUnreleased($film)) {
                continue;
            }
            $at = $this->movieAsOfTimestamp($film);
            if ($at !== null && ($latest === null || strcmp($at, $latest) > 0)) {
                $latest = $at;
            }
        }

        return $latest ?? $this->clampAtomTimestamp($fallback);
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $otherCatalog
     * @param  array<string, mixed>  $brandCatalog
     */
    private function seriesIndexHtml(array $catalog, array $otherCatalog, array $brandCatalog = []): string
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $series = $this->listedSeries($catalog);
        $heading = $isJapan ? '日本の映画シリーズ興行収入' : '世界の映画シリーズ興行収入';
        $pageTitle = $heading.' | MUBIRAN';
        $description = $isJapan
            ? '日本で公開された映画シリーズの累計興行収入。名探偵コナン、ポケットモンスターなど、シリーズごとの作品一覧と興収合計。'
            : '世界興行収入をもとにした映画シリーズ（フランチャイズ）の累計。TMDBのコレクション情報から作品をまとめています。';
        if ($series !== []) {
            $top = $series[0];
            $topName = (string) ($top['name'] ?? '');
            $topTotal = $this->seriesAmountLabel($top, $isJapan);
            if ($topName !== '' && $topTotal !== '不明') {
                $description = $heading.'。1位は'.$topName.'の'.$this->seriesTotalWithNote($top, $isJapan).'。';
            }
        }

        $otherHref = $isJapan ? '/series/global/' : '/series/';
        $otherLabel = $isJapan ? '世界のシリーズ興行収入' : '日本のシリーズ興行収入';
        $rows = '';
        foreach ($series as $index => $item) {
            $href = $this->h((string) ($item['path'] ?? '#'));
            $name = $this->h((string) ($item['name'] ?? ''));
            $count = (int) ($item['filmCount'] ?? count($item['films'] ?? []));
            $total = $this->h($this->seriesAmountLabel($item, $isJapan));
            $rows .= '<tr><td class="series-rank">'.($index + 1).'</td>'
                .'<td><a href="'.$href.'">'.$name.'</a></td>'
                .'<td class="series-num">'.$count.'</td>'
                .'<td class="series-num">'.$total.'</td></tr>'."\n";
        }

        $attribution = $isJapan ? $this->japanSeriesAttribution($catalog) : $this->worldSeriesAttribution();
        $brandIndex = $isJapan ? '/series/brand/' : '/series/global/brand/';
        $brandLinks = $this->brandNavHtml($brandCatalog, $brandIndex);
        $body = '<p class="series-lead">'.$this->h($isJapan
            ? 'Wikipediaの「映画シリーズの興行成績」に掲載されたシリーズです。作品ごとの日本興収と、シリーズ合計を掲載しています。'
            : '世界興収ランキングの作品が属するTMDBコレクションです。興収が分かる作品の世界興収を集計しています。'
        ).'</p>'
            .'<p class="ranking-static-switch"><a href="'.$this->h($otherHref).'">'.$this->h($otherLabel).'</a>'
            .($brandLinks !== '' ? ' · <a href="'.$this->h($brandIndex).'">スタジオ・ユニバース別</a>' : '')
            .'</p>'
            .$brandLinks
            .'<table class="series-table"><thead><tr><th>順位</th><th>シリーズ</th><th>作品数</th><th>合計</th></tr></thead><tbody>'
            .$rows.'</tbody></table>'
            .$attribution;

        $jsonLd = $this->seriesIndexJsonLd($catalog, $heading, $this->seriesCatalogLastmod($catalog));

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
        $isBrand = ($series['kind'] ?? $catalog['kind'] ?? '') === 'brand';
        $name = (string) ($series['name'] ?? '');
        $heading = (string) ($series['heading'] ?? ($name.'のシリーズ興行収入'));
        $amountLabel = $this->seriesAmountLabel($series, $isJapan);
        $totalLabel = $this->seriesTotalWithNote($series, $isJapan);
        $pageTitle = $isBrand
            ? $heading.' | MUBIRAN'
            : $heading.' '.$amountLabel.' | MUBIRAN';
        $filmCount = (int) ($series['filmCount'] ?? count($series['films'] ?? []));
        $description = $isBrand
            ? $heading.'。'.$filmCount.'作品、合計'.$totalLabel.'。'
            : $name.'シリーズの'.($isJapan ? '日本' : '世界').'興行収入。'
                .$filmCount.'作品、合計'.$totalLabel.'。';
        $movies ??= $this->seriesMoviesForUi($series, $isJapan);
        $payload = $this->seriesClientPayload($series, $catalog, $movies);
        $indexHref = $isBrand
            ? (string) ($catalog['indexPath'] ?? ($isJapan ? '/series/brand/' : '/series/global/brand/'))
            : ($isJapan ? '/series/' : '/series/global/');
        $parentHref = $isJapan ? '/series/' : '/series/global/';
        $otherHref = (string) ($series['otherHref'] ?? ($isJapan ? '/series/global/' : '/series/'));
        $otherLabel = (string) ($series['otherLabel'] ?? ($isJapan ? '世界のシリーズ興行収入' : '日本のシリーズ興行収入'));
        $rankingHref = $isJapan ? '/japan/' : '/';
        $nowHref = $isJapan ? '/now/' : '/now/global/';
        $crumb = $isJapan ? '日本のシリーズ' : '世界のシリーズ';
        $robots = ! empty($series['indexable']) ? 'index, follow' : 'noindex, follow';
        $jsonSrc = $isBrand
            ? '/data/series/'.($isJapan ? 'japan' : 'global').'/brand/'.rawurlencode((string) ($series['slug'] ?? '')).'.json'
            : '/data/series/'.($isJapan ? 'japan' : 'global').'/'.rawurlencode((string) ($series['slug'] ?? '')).'.json';
        $totalDt = $isBrand ? '合計' : 'シリーズ合計';

        $stats = '<dl class="series-stats">'
            .'<div><dt>作品数</dt><dd>'.$filmCount.'</dd></div>'
            .'<div><dt>'.$totalDt.'</dt><dd>'.$this->h($amountLabel).'</dd></div>';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $stats .= '<div><dt>配給収入の作品</dt><dd>'.$this->h($this->yenLabel($series['sumRentalsYen'] ?? null)).'</dd></div>';
        }
        $stats .= '</dl>';
        $metricNote = '';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $metricNote = '<p class="series-metric-note">古い作品は配給収入で記録されていることがあります。配給収入と興行収入は別の指標なので、合計には混ぜていません。</p>';
        }

        $rows = '';
        foreach ($movies as $movie) {
            $rows .= $this->seriesMovieRowHtml($movie, $isJapan);
        }
        $listHtml = $movies === []
            ? '<p class="static-empty text-center text-white">データがありません。</p>'
            : '<section class="ranking-list" id="series-ranking-list">'.$rows.'</section>';

        $attribution = $isBrand
            ? $this->brandAttribution($isJapan)
            : ($isJapan ? $this->japanSeriesAttribution($catalog) : $this->worldSeriesAttribution());
        $dateModified = $this->seriesLastmod($series, $this->seriesCatalogLastmod($catalog));
        $jsonLd = $this->seriesDetailJsonLd($series, $catalog, $movies, $dateModified);
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
        $eParent = $this->h($parentHref);
        $eIndex = $this->h($indexHref);
        $eOther = $this->h($otherHref);
        $eOtherLabel = $this->h($otherLabel);
        $brandCrumb = $isBrand
            ? '<a href="'.$eIndex.'">ブランド</a><span aria-hidden="true"> › </span>'
            : '';
        $backIndexLabel = $isBrand ? 'ブランド一覧に戻る' : 'シリーズ一覧に戻る';
        $eBackIndexLabel = $this->h($backIndexLabel);
        $regionGlobal = $isBrand ? '/series/global/brand/' : '/series/global/';
        $regionJapan = $isBrand ? '/series/brand/' : '/series/';
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
            <a href="{$regionGlobal}" class="toggle-btn {$this->h($isJapan ? '' : 'active')}">世界</a>
            <a href="{$regionJapan}" class="toggle-btn {$this->h($isJapan ? 'active' : '')}">日本</a>
          </div>
        </div>
      </header>
      <main class="container">
        <nav class="content-breadcrumb" aria-label="パンくずリスト">
          <a href="/">ホーム</a><span aria-hidden="true"> › </span>
          <a href="{$eParent}">{$eCrumb}</a><span aria-hidden="true"> › </span>
          {$brandCrumb}<span>{$eName}</span>
        </nav>
        <h1 class="page-title" id="page-title">{$eHeading}</h1>
        {$stats}
        {$metricNote}
        {$listHtml}
        {$attribution}
        <p class="content-back-link"><a href="{$eIndex}">{$eBackIndexLabel}</a> · <a href="{$eOther}">{$eOtherLabel}</a></p>
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
        $isBrand = ($series['kind'] ?? $catalog['kind'] ?? '') === 'brand';
        $name = (string) ($series['name'] ?? '');

        return [
            'region' => $isJapan ? 'japan' : 'global',
            'kind' => $isBrand ? 'brand' : 'series',
            'name' => $name,
            'heading' => $isBrand
                ? (string) ($series['heading'] ?? $name)
                : $name.'のシリーズ興行収入',
            'path' => $series['path'] ?? '',
            'slug' => $series['slug'] ?? '',
            'indexPath' => $isBrand
                ? (string) ($catalog['indexPath'] ?? ($isJapan ? '/series/brand/' : '/series/global/brand/'))
                : ($isJapan ? '/series/' : '/series/global/'),
            'crumb' => $isJapan ? '日本のシリーズ' : '世界のシリーズ',
            'usdJpy' => $this->usdJpy(),
            'filmCount' => (int) ($series['filmCount'] ?? count($movies)),
            'totalLabel' => $this->seriesAmountLabel($series, $isJapan),
            'excludedUnknownNote' => SeriesCatalog::excludedUnknownNote($series),
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
            if (is_array($film) && SeriesCatalog::filmHasDisplayableAmount($film)) {
                $films[] = $film;
            }
        }
        $films = SeriesCatalog::sortFilmsByBoxOffice($films);
        $movies = [];
        $seriesRank = 0;
        foreach ($films as $film) {
            $id = $this->seriesFilmId($film);
            $title = (string) ($film['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $unreleased = SeriesCatalog::filmIsUnreleased($film);
            $boxOffice = is_numeric($film['boxOffice'] ?? null) ? (int) $film['boxOffice'] : 0;
            $known = ! $unreleased && $boxOffice > 0;
            $ranked = ! $unreleased && SeriesCatalog::displayGroup($film) === 0;
            $displayRank = null;
            if ($ranked) {
                $seriesRank++;
                $displayRank = $seriesRank;
            }
            $rankingPosition = ! $unreleased && ! empty($film['inRanking']) && is_numeric($film['rank'] ?? null)
                ? (int) $film['rank']
                : null;
            $revenue = $unreleased ? '公開予定' : $this->seriesFilmRevenue($film, $isJapan);
            $movies[] = [
                'id' => $id,
                'slug' => (string) ($film['slug'] ?? $id),
                'tmdbId' => is_numeric($film['tmdbId'] ?? null) ? (int) $film['tmdbId'] : null,
                'rank' => $displayRank,
                'rankingPosition' => $rankingPosition,
                'inRanking' => $rankingPosition !== null,
                'title' => $title,
                'originalTitle' => (string) ($film['originalTitle'] ?? ''),
                'releaseDate' => $film['releaseDate'] ?? null,
                'releaseYear' => $film['releaseYear'] ?? null,
                'genres' => is_array($film['genres'] ?? null) ? $film['genres'] : [],
                'posterUrl' => (string) ($film['posterUrl'] ?? ''),
                'isActive' => $unreleased ? false : (bool) ($film['isActive'] ?? false),
                'unreleased' => $unreleased,
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
        if (! is_numeric($boxOffice) || (int) $boxOffice <= 0) {
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
     * 歴代ランキングと同じ list-item。1–3 位は同じ金銀銅クラスを付ける。
     *
     * @param  array<string, mixed>  $movie
     */
    private function seriesMovieRowHtml(array $movie, bool $isJapan): string
    {
        $url = (string) ($movie['posterUrl'] ?? '');
        $bg = $url !== '' ? '<div class="list-bg-image" style="background-image:url(\''.$this->h($url).'\')"></div>' : '';
        $id = $this->h((string) ($movie['id'] ?? ''));
        $title = (string) ($movie['title'] ?? '');
        $href = $this->h((string) ($movie['href'] ?? '/movies/'.$movie['id'].'/'));
        $titleClass = $this->seriesTitleClass($title);
        $chip = $this->seriesRankingChipHtml($movie);
        $active = ! empty($movie['isActive']) ? ' active-movie' : '';
        $japan = $isJapan ? ' is-japan' : '';
        $rental = (($movie['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS) ? ' is-rental' : '';
        $upcoming = ! empty($movie['unreleased']) ? ' is-upcoming' : '';
        $badge = $this->seriesStatusBadgeHtml($movie);
        $subtitle = $this->seriesOriginalTitleHtml($movie, 'span');
        $yen = ! empty($movie['revenueYen'])
            ? '<span class="revenue-sub">'.$this->h((string) $movie['revenueYen']).'</span>'
            : '';
        $boxOffice = (($movie['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS) || ! empty($movie['unreleased'])
            ? 0
            : (int) ($movie['boxOffice'] ?? 0);
        $max = $isJapan ? 150 : 3;
        $width = $boxOffice > 0 ? min(($boxOffice / 100000000) / $max * 100, 100) : 0;
        $rankLabel = $this->seriesRankLabel($movie);
        $unranked = is_numeric($movie['rank'] ?? null) && (int) $movie['rank'] > 0 ? '' : ' list-rank-unranked';
        $medal = '';
        if (is_numeric($movie['rank'] ?? null) && (int) $movie['rank'] >= 1 && (int) $movie['rank'] <= 3) {
            $medal = ' rank-'.(int) $movie['rank'];
        }

        return '<article class="list-item'.$medal.$active.$japan.$rental.$upcoming.'" data-movie-id="'.$id.'" role="button" tabindex="0" aria-label="'.$this->h($title).'の詳細を見る">'
            .$badge
            .'<div class="revenue-bar-bg" style="width: '.$width.'%;"></div>'
            .$bg
            .'<div class="list-rank'.$unranked.'">'.$this->h($rankLabel).'</div>'
            .'<div class="list-info"><div class="title-line"><a class="movie-title '.$titleClass.'" href="'.$href.'">'.$this->h($title).'</a>'.$chip.'</div>'.$subtitle.'</div>'
            .'<div class="list-revenue"><span class="revenue-main">'.$this->h((string) ($movie['revenue'] ?? '不明')).'</span>'.$yen.'</div>'
            .'</article>';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesStatusBadgeHtml(array $movie): string
    {
        if (! empty($movie['unreleased'])) {
            return '<div class="upcoming-badge-card">公開予定</div>';
        }
        if (! empty($movie['isActive'])) {
            return '<div class="active-badge-card">公開中</div>';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesRankingChipHtml(array $movie): string
    {
        if (! is_numeric($movie['rankingPosition'] ?? null)) {
            return '';
        }
        $rank = (string) (int) $movie['rankingPosition'];

        return '<span class="news-trigger-btn series-ranking-chip" title="歴代ランキング">'.$this->h($rank).'</span>';
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private function seriesRankLabel(array $movie): string
    {
        if (is_numeric($movie['rank'] ?? null) && (int) $movie['rank'] > 0) {
            return (string) (int) $movie['rank'];
        }

        return '—';
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
    private function seriesIndexJsonLd(array $catalog, string $name, ?string $dateModified = null): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $path = $catalog['indexPath'] ?? '/series/';
        $elements = [];
        foreach ($this->listedSeries($catalog) as $series) {
            if (empty($series['path']) || empty($series['name'])) {
                continue;
            }
            $elements[] = [
                '@type' => 'ListItem',
                'position' => count($elements) + 1,
                'name' => $series['name'],
                'url' => $baseUrl.$series['path'],
            ];
        }

        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $isBrand = ($catalog['kind'] ?? '') === 'brand';
        $crumb = $isJapan ? '日本のシリーズ' : '世界のシリーズ';
        $parentPath = $isJapan ? '/series/' : '/series/global/';
        $itemList = array_filter([
            '@type' => 'ItemList',
            'name' => $name,
            'dateModified' => $dateModified,
            'itemListElement' => $elements,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $crumbs = [
            ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $baseUrl.'/', 'name' => 'ホーム']],
            ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $baseUrl.($isBrand ? $parentPath : $path), 'name' => $crumb]],
        ];
        if ($isBrand) {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $baseUrl.$path, 'name' => 'ブランド']];
        }
        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $crumbs,
                ],
                $itemList,
            ],
        ];

        return '<script type="application/ld+json">'.json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>';
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $movies
     */
    private function seriesDetailJsonLd(array $series, array $catalog, array $movies = [], ?string $dateModified = null): string
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $indexPath = $catalog['indexPath'] ?? '/series/';
        $path = (string) ($series['path'] ?? $indexPath);
        $name = (string) ($series['name'] ?? '');
        $items = $movies !== [] ? $movies : ($series['films'] ?? []);
        $elements = [];
        foreach ($items as $offset => $film) {
            if (! is_array($film) || ($film['title'] ?? '') === '') {
                continue;
            }
            $item = [
                '@type' => 'ListItem',
                'position' => $offset + 1,
                'name' => $film['title'],
            ];
            $href = $film['href'] ?? '';
            if ($href === '' && ! empty($film['id'])) {
                $href = '/movies/'.$film['id'].'/';
            }
            if ($href !== '') {
                $item['url'] = $baseUrl.$href;
            }
            $elements[] = $item;
        }

        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $isBrand = ($series['kind'] ?? $catalog['kind'] ?? '') === 'brand';
        $parentPath = $isJapan ? '/series/' : '/series/global/';
        $indexName = $isJapan ? '日本のシリーズ' : '世界のシリーズ';
        $listName = $isBrand
            ? (string) ($series['heading'] ?? $name)
            : $name.'のシリーズ興行収入';
        $listDescription = $isBrand
            ? $listName.'。'.count($elements).'作品、合計'.$this->seriesTotalWithNote($series, $isJapan).'。'
            : $name.'シリーズの'.($isJapan ? '日本' : '世界').'興行収入。'
                .count($elements).'作品、合計'.$this->seriesTotalWithNote($series, $isJapan).'。';
        $itemList = array_filter([
            '@type' => 'ItemList',
            'name' => $listName,
            'description' => $listDescription,
            'dateModified' => $dateModified,
            'itemListElement' => $elements,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $crumbs = [
            ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $baseUrl.'/', 'name' => 'ホーム']],
            ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $baseUrl.$parentPath, 'name' => $indexName]],
        ];
        if ($isBrand) {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $baseUrl.$indexPath, 'name' => 'ブランド']];
            $crumbs[] = ['@type' => 'ListItem', 'position' => 4, 'item' => ['@id' => $baseUrl.$path, 'name' => $name]];
        } else {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $baseUrl.$path, 'name' => $name]];
        }
        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $crumbs,
                ],
                $itemList,
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

        return '<p class="series-attr">日本のシリーズ興行収入は Wikipedia『<a href="'.$wikiUrl.'" target="_blank" rel="noreferrer">日本歴代興行成績上位の映画一覧</a>』の「映画シリーズの興行成績」'.$date.'を出典としています（<a href="'.$ccUrl.'" target="_blank" rel="noreferrer">CC BY-SA 4.0</a>）。興収が分からない作品は掲載していません。</p>';
    }

    private function worldSeriesAttribution(): string
    {
        return '<p class="series-attr">世界のシリーズ区分と興行収入は <a href="https://www.themoviedb.org/" target="_blank" rel="noreferrer">TMDB</a> のコレクション（belongs_to_collection）と作品の revenue を出典としています。</p>';
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return list<array<string, mixed>>
     */
    private function listedSeries(array $catalog): array
    {
        $listed = [];
        foreach ($catalog['series'] ?? [] as $series) {
            if (is_array($series) && SeriesCatalog::hasListedFilms($series)) {
                $listed[] = $series;
            }
        }

        return $listed;
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function seriesAmountLabel(array $series, bool $isJapan): string
    {
        return $isJapan
            ? $this->yenLabel($series['totalYen'] ?? null)
            : $this->usdLabel($series['totalUsd'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function seriesTotalWithNote(array $series, bool $isJapan): string
    {
        $amount = $this->seriesAmountLabel($series, $isJapan);
        $note = SeriesCatalog::excludedUnknownNote($series);

        return $note === null ? $amount : $amount.'（'.$note.'）';
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

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $otherCatalog
     */
    private function brandIndexHtml(array $catalog, array $otherCatalog): string
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $brands = $this->listedSeries($catalog);
        $heading = $isJapan ? '日本の映画スタジオ・ユニバース興行収入' : '世界の映画スタジオ・ユニバース興行収入';
        $pageTitle = $heading.' | MUBIRAN';
        $description = $isJapan
            ? '日本の歴代興行収入ランキングに載っている作品を、ディズニー・アニメーション、ピクサー、スタジオジブリなどの制作スタジオやユニバース別に集計した興行収入。'
            : '世界の歴代興行収入ランキングに載っている作品を、MCU、ピクサー、DCなどの制作スタジオやユニバース別に集計した興行収入。';
        if ($brands !== []) {
            $top = $brands[0];
            $topName = (string) ($top['name'] ?? '');
            $topTotal = $this->seriesAmountLabel($top, $isJapan);
            if ($topName !== '' && $topTotal !== '不明') {
                $description = $heading.'。1位は'.$topName.'の'.$topTotal.'。';
            }
        }

        $otherHref = $isJapan ? '/series/global/brand/' : '/series/brand/';
        $otherLabel = $isJapan ? '世界のスタジオ・ユニバース' : '日本のスタジオ・ユニバース';
        $parentHref = $isJapan ? '/series/' : '/series/global/';
        $rows = '';
        foreach ($brands as $index => $item) {
            $href = $this->h((string) ($item['path'] ?? '#'));
            $name = $this->h((string) ($item['name'] ?? ''));
            $count = (int) ($item['filmCount'] ?? count($item['films'] ?? []));
            $total = $this->h($this->seriesAmountLabel($item, $isJapan));
            $rows .= '<tr><td class="series-rank">'.($index + 1).'</td>'
                .'<td><a href="'.$href.'">'.$name.'</a></td>'
                .'<td class="series-num">'.$count.'</td>'
                .'<td class="series-num">'.$total.'</td></tr>'."\n";
        }

        $body = '<p class="series-lead">'.$this->h($isJapan
            ? '日本の歴代ランキングに載っている作品だけを、制作スタジオやユニバースごとに集計しています。配給だけの作品は入れていません。'
            : '世界の歴代ランキングに載っている作品だけを、制作スタジオやユニバースごとに集計しています。配給だけの作品は入れていません。'
        ).'</p>'
            .'<p class="ranking-static-switch"><a href="'.$this->h($otherHref).'">'.$this->h($otherLabel).'</a>'
            .' · <a href="'.$this->h($parentHref).'">シリーズ一覧</a></p>'
            .'<table class="series-table"><thead><tr><th>順位</th><th>ブランド</th><th>作品数</th><th>合計</th></tr></thead><tbody>'
            .$rows.'</tbody></table>'
            .$this->brandAttribution($isJapan);

        $jsonLd = $this->seriesIndexJsonLd($catalog, $heading, $this->seriesCatalogLastmod($catalog));
        $crumb = $isJapan ? '日本のシリーズ' : '世界のシリーズ';

        return $this->seriesPageShell(
            $catalog['indexPath'] ?? ($isJapan ? '/series/brand/' : '/series/global/brand/'),
            $pageTitle,
            $description,
            'ブランド',
            $heading,
            $body,
            $jsonLd,
            [['href' => $parentHref, 'name' => $crumb]],
            'index, follow',
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function brandNavHtml(array $catalog, string $indexHref): string
    {
        $brands = $this->listedSeries($catalog);
        if ($brands === []) {
            return '';
        }
        $links = [];
        foreach ($brands as $brand) {
            $href = (string) ($brand['path'] ?? '');
            $name = (string) ($brand['name'] ?? '');
            if ($href === '' || $name === '') {
                continue;
            }
            $links[] = '<a href="'.$this->h($href).'">'.$this->h($name).'</a>';
        }
        if ($links === []) {
            return '';
        }

        return '<p class="series-lead">スタジオ・ユニバース別: '.implode(' · ', $links)
            .' （<a href="'.$this->h($indexHref).'">一覧</a>）</p>';
    }

    /**
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $otherCatalog
     * @return array<string, mixed>
     */
    private function withBrandSibling(array $brand, array $otherCatalog): array
    {
        $slug = (string) ($brand['slug'] ?? '');
        $isJapan = ($brand['region'] ?? '') === 'japan';
        $sibling = null;
        foreach ($otherCatalog['series'] ?? [] as $item) {
            if (is_array($item) && ($item['slug'] ?? '') === $slug) {
                $sibling = $item;
                break;
            }
        }
        if (is_array($sibling) && ($sibling['path'] ?? '') !== '') {
            $brand['otherHref'] = (string) $sibling['path'];
            $brand['otherLabel'] = (($sibling['region'] ?? '') === 'japan' ? '日本の' : '世界の').(string) ($brand['name'] ?? '');
        } else {
            $brand['otherHref'] = (string) ($otherCatalog['indexPath'] ?? ($isJapan ? '/series/global/brand/' : '/series/brand/'));
            $brand['otherLabel'] = $isJapan ? '世界のスタジオ・ユニバース' : '日本のスタジオ・ユニバース';
        }

        return $brand;
    }

    private function brandAttribution(bool $isJapan): string
    {
        $scope = $isJapan ? '日本の歴代興行収入ランキング' : '世界の歴代興行収入ランキング';

        return '<p class="series-attr">'.$this->h($scope).'に載っている作品だけを集計しています。どの作品がどのスタジオ・ユニバースかは、配給だけの関係を除いた対応表に基づきます。</p>';
    }
}
