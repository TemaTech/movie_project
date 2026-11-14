<?php

namespace App\Console\Traits;

use App\Services\Series\JapanBoxOfficeAmount;
use App\Services\Series\JapanSeriesWikipediaParser;
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

        foreach ($catalog['series'] ?? [] as $series) {
            if (! is_array($series) || empty($series['slug'])) {
                continue;
            }
            $dir = $indexDir.'/'.$series['slug'];
            File::ensureDirectoryExists($dir);
            File::put($dir.'/index.html', $this->seriesDetailHtml($series, $catalog));
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
            if (! is_array($series) || empty($series['path'])) {
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

        return $this->seriesPageShell(
            $catalog['indexPath'] ?? ($isJapan ? '/series/' : '/series/global/'),
            $pageTitle,
            $description,
            $isJapan ? 'シリーズ' : '世界のシリーズ',
            $heading,
            $body,
            $jsonLd,
            $isJapan ? [['href' => '/japan/', 'name' => '日本ランキング']] : [],
        );
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     */
    private function seriesDetailHtml(array $series, array $catalog): string
    {
        $isJapan = ($catalog['region'] ?? '') === 'japan';
        $name = (string) ($series['name'] ?? '');
        $heading = $name.'のシリーズ興行収入';
        $totalLabel = $isJapan
            ? $this->yenLabel($series['totalYen'] ?? null)
            : $this->usdLabel($series['totalUsd'] ?? null);
        $pageTitle = $heading.' '.$totalLabel.' | MUBIRAN';
        $filmCount = (int) ($series['filmCount'] ?? 0);
        $description = $name.'シリーズの'.($isJapan ? '日本' : '世界').'興行収入。'
            .$filmCount.'作品、合計'.$totalLabel.'。';

        $rows = '';
        foreach ($series['films'] ?? [] as $film) {
            if (! is_array($film)) {
                continue;
            }
            $title = $this->h((string) ($film['title'] ?? ''));
            $href = is_string($film['href'] ?? null) ? (string) $film['href'] : '';
            $titleHtml = $href !== '' ? '<a href="'.$this->h($href).'">'.$title.'</a>' : $title;
            $year = $film['releaseYear'] ?? '';
            $bo = $film['boxOffice'] ?? null;
            $boLabel = $isJapan ? $this->yenLabel($bo) : $this->usdLabel($bo);
            if (($film['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS && $bo) {
                $boLabel .= '（配給収入）';
            }
            $rank = is_numeric($film['rank'] ?? null) ? (string) (int) $film['rank'] : '—';
            $rows .= '<tr><td>'.$this->h((string) $year).'</td><td>'.$titleHtml.'</td>'
                .'<td class="series-num">'.$this->h($boLabel).'</td>'
                .'<td class="series-num">'.$this->h($rank).'</td></tr>'."\n";
        }

        $stats = '<dl class="series-stats">'
            .'<div><dt>作品数</dt><dd>'.$filmCount.'</dd></div>'
            .'<div><dt>シリーズ合計</dt><dd>'.$this->h($totalLabel).'</dd></div>';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $stats .= '<div><dt>配給収入の作品</dt><dd>'.$this->h($this->yenLabel($series['sumRentalsYen'] ?? null)).'</dd></div>';
        }
        $stats .= '</dl>';
        if ($isJapan && ! empty($series['hasRentals'])) {
            $stats .= '<p class="series-metric-note">古い作品は配給収入で記録されていることがあります。配給収入と興行収入は別の指標なので、合計には混ぜていません。</p>';
        }

        $indexHref = $isJapan ? '/series/' : '/series/global/';
        $body = $stats
            .'<table class="series-table"><thead><tr><th>年</th><th>作品</th><th>興行収入</th><th>ランキング</th></tr></thead><tbody>'
            .$rows.'</tbody></table>'
            .($isJapan ? $this->japanSeriesAttribution($catalog) : $this->worldSeriesAttribution())
            .'<p class="content-back-link"><a href="'.$this->h($indexHref).'">シリーズ一覧に戻る</a></p>';

        $jsonLd = $this->seriesDetailJsonLd($series, $catalog);

        return $this->seriesPageShell(
            (string) ($series['path'] ?? $indexHref),
            $pageTitle,
            $description,
            $name,
            $heading,
            $body,
            $jsonLd,
            [
                ['href' => $indexHref, 'name' => $isJapan ? '日本のシリーズ' : '世界のシリーズ'],
            ],
        );
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

        return <<<HTML
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$eDescription}">
    <meta name="robots" content="index, follow">
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

        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $baseUrl.'/', 'name' => 'ホーム']],
                        ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $baseUrl.$path, 'name' => $name]],
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
