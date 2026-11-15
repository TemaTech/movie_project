<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Services\Series\JapanSeriesSnapshot;
use App\Services\Series\SeriesCatalog;
use App\Services\Series\SeriesFilmMatcher;
use App\Services\Series\SeriesSlug;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class SeriesExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureViteManifest();
    }

    public function test_japan_snapshot_includes_conan_and_every_listed_series(): void
    {
        $catalog = SeriesCatalog::japan([]);

        $this->assertGreaterThanOrEqual(40, count($catalog['series']));
        $this->assertLessThan(count(JapanSeriesSnapshot::series()), count($catalog['series']));
        foreach ($catalog['series'] as $series) {
            $this->assertGreaterThan(0, (int) $series['filmCount'], (string) ($series['name'] ?? ''));
        }
        $conan = collect($catalog['series'])->firstWhere('name', '名探偵コナン');
        $this->assertIsArray($conan);
        $theatrical = array_values(array_filter(
            $conan['films'],
            fn (array $film) => ($film['kind'] ?? '') === 'theatrical'
        ));
        $this->assertCount(29, $theatrical);
        $this->assertSame('/series/名探偵コナン/', $conan['path']);
        $this->assertNotNull($conan['totalYen']);
        $this->assertTrue(collect($theatrical)->contains(
            fn (array $film) => str_contains((string) $film['title'], '時計じかけの摩天楼')
        ));
    }

    public function test_series_index_and_detail_follow_seo_conventions(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $catalog = [
            'region' => 'japan',
            'indexPath' => '/series/',
            'retrievedAt' => '2026-11-14',
            'sourceUrl' => 'https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja',
            'series' => [[
                'slug' => '名探偵コナン',
                'name' => '名探偵コナン',
                'path' => '/series/名探偵コナン/',
                'filmCount' => 29,
                'totalYen' => 149_460_000_000,
                'hasRentals' => false,
                'films' => [[
                    'title' => '名探偵コナン 時計じかけの摩天楼',
                    'releaseYear' => 1997,
                    'boxOffice' => 1_100_000_000,
                    'metric' => 'box_office',
                    'kind' => 'theatrical',
                    'href' => '/movies/jp-tmdb-1/',
                    'rank' => 40,
                ]],
            ]],
        ];
        $other = ['region' => 'global', 'indexPath' => '/series/global/', 'series' => []];

        $index = $this->invoke('seriesIndexHtml', $catalog, $other);
        $this->assertStringContainsString('<title>日本の映画シリーズ興行収入 | MUBIRAN</title>', $index);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/"', $index);
        $this->assertStringContainsString('og:description', $index);
        $this->assertStringContainsString('ItemList', $index);
        $this->assertStringContainsString('BreadcrumbList', $index);
        $this->assertStringContainsString('href="/series/名探偵コナン/"', $index);
        $this->assertStringContainsString('CC BY-SA', $index);
        $this->assertStringContainsString('href="/series/global/"', $index);
        $this->assertStringContainsString('日本のシリーズ', $index);
        $this->assertStringNotContainsString('日本ランキング', $index);

        $catalog['series'][0]['indexable'] = true;
        $detail = $this->invoke('seriesDetailHtml', $catalog['series'][0], $catalog);
        $this->assertStringContainsString('名探偵コナンのシリーズ興行収入', $detail);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/名探偵コナン/"', $detail);
        $this->assertStringContainsString('時計じかけの摩天楼', $detail);
        $this->assertStringContainsString('11.0億円', $detail);
        $this->assertStringNotContainsString('配給収入と興行収入は別の指標', $detail);
        $this->assertStringContainsString('ItemList', $detail);
        $this->assertStringContainsString('Wikipedia', $detail);
        $this->assertStringContainsString('href="/series/"', $detail);
        $this->assertStringContainsString('日本のシリーズ', $detail);
        $this->assertStringContainsString('name":"日本のシリーズ"', $detail);
        $this->assertStringContainsString('id="app"', $detail);
        $this->assertStringContainsString('data-series-src=', $detail);
        $this->assertStringContainsString('list-item', $detail);
        $this->assertStringContainsString('data-movie-id="jp-tmdb-1"', $detail);
        $this->assertStringNotContainsString('top-card', $detail);
        $this->assertStringNotContainsString('series-table', $detail);
    }

    public function test_series_pages_are_listed_in_the_sitemap_with_data_dates(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = sys_get_temp_dir().'/mubiran-series-sitemap-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->invoke(
                'exportSitemap',
                $dir,
                [],
                [],
                [],
                null,
                null,
                [],
                [],
                $this->invoke('seriesSitemapEntries', [
                    'indexPath' => '/series/',
                    'retrievedAt' => '2026-11-14',
                    'series' => [[
                        'path' => '/series/名探偵コナン/',
                        'indexable' => true,
                        'films' => [
                            ['title' => 'a', 'boxOffice' => 1],
                            ['title' => 'b', 'boxOffice' => 1],
                        ],
                    ], [
                        'path' => '/series/thin/',
                        'indexable' => false,
                        'films' => [['title' => 'alone']],
                    ]],
                ]),
            );
            $xml = (string) file_get_contents($dir.'/sitemap.xml');
        } finally {
            @unlink($dir.'/sitemap.xml');
            @rmdir($dir);
        }

        $this->assertStringContainsString('<loc>https://mubiran.com/series/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://mubiran.com/series/名探偵コナン/</loc>', $xml);
        $this->assertStringNotContainsString('/series/thin/', $xml);
        $this->assertStringContainsString('<lastmod>2026-11-14T00:00:00+09:00</lastmod>', $xml);
        $this->assertStringContainsString('<loc>https://mubiran.com/</loc>', $xml);
    }

    public function test_thin_series_and_empty_film_pages_are_noindex(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $thin = [
            'slug' => 'thin',
            'name' => 'Thin',
            'path' => '/series/thin/',
            'filmCount' => 1,
            'knownCount' => 0,
            'totalYen' => null,
            'indexable' => false,
            'films' => [['title' => 'Alone', 'boxOffice' => null, 'href' => '/movies/jp-series-abc/']],
        ];
        $html = $this->invoke('seriesDetailHtml', $thin, [
            'region' => 'japan',
            'indexPath' => '/series/',
        ]);
        $this->assertStringContainsString('noindex, follow', $html);

        $page = $this->invoke('moviePageHtml', [
            'id' => 'jp-series-abc',
            'title' => 'Alone',
            'revenue' => '不明',
            'boxOffice' => 0,
            'rank' => null,
            'inRanking' => false,
            'posterUrl' => '',
            'genres' => [],
        ], true, sys_get_temp_dir(), []);
        $this->assertStringContainsString('noindex, follow', $page);
    }

    public function test_conan_ranking_column_links_top_200_films(): void
    {
        $catalog = SeriesCatalog::japan([[
            'id' => 'jp-tmdb-1545621',
            'title' => '名探偵コナン ハイウェイの堕天使',
            'releaseYear' => 2026,
            'rank' => 28,
            'boxOffice' => 13_700_000_000,
        ], [
            'id' => 'jp-tmdb-1209217',
            'title' => '名探偵コナン 100万ドルの五稜星',
            'releaseYear' => 2024,
            'rank' => 17,
            'boxOffice' => 15_800_000_000,
        ]]);
        $conan = collect($catalog['series'])->firstWhere('name', '名探偵コナン');
        $this->assertIsArray($conan);
        $highway = collect($conan['films'])->first(fn (array $film) => str_contains((string) $film['title'], 'ハイウェイの堕天使'));
        $this->assertSame(28, $highway['rank']);
        $this->assertSame('/movies/jp-tmdb-1545621/', $highway['href']);

        $html = $this->invoke('seriesDetailHtml', $conan, $catalog);
        $this->assertStringContainsString('href="/movies/jp-tmdb-1545621/"', $html);
        $this->assertStringContainsString('data-movie-id="jp-tmdb-1545621"', $html);
        $this->assertStringContainsString('news-trigger-btn series-ranking-chip', $html);
        $this->assertStringContainsString('>28</span>', $html);
        $this->assertStringContainsString('list-item', $html);
        $this->assertStringNotContainsString('top-card', $html);
        $this->assertStringNotContainsString('series-release-year', $html);
        $this->assertStringContainsString('<article class="list-item rank-1 is-japan" data-movie-id="jp-tmdb-1209217"', $html);
        $this->assertStringNotContainsString('<table class="series-table">', $html);
    }

    public function test_series_detail_labels_rentals_and_unknown_figures(): void
    {
        $html = $this->invoke('seriesDetailHtml', [
            'slug' => 'doraemon',
            'name' => 'ドラえもん',
            'path' => '/series/doraemon/',
            'filmCount' => 2,
            'knownCount' => 1,
            'totalYen' => 1_500_000_000,
            'hasRentals' => true,
            'sumRentalsYen' => 1_500_000_000,
            'indexable' => true,
            'films' => [[
                'id' => 'jp-series-old',
                'title' => 'ドラえもん 古い作品',
                'releaseYear' => 1980,
                'boxOffice' => 1_500_000_000,
                'metric' => 'distributor_rentals',
                'href' => '/movies/jp-series-old/',
            ], [
                'id' => 'jp-series-new',
                'title' => 'ドラえもん 数字なし',
                'releaseYear' => 2024,
                'boxOffice' => null,
                'metric' => 'box_office',
                'href' => '/movies/jp-series-new/',
            ]],
        ], [
            'region' => 'japan',
            'indexPath' => '/series/',
        ]);

        $this->assertStringContainsString('（配給収入）', $html);
        $this->assertStringNotContainsString('ドラえもん 数字なし', explode('id="series-ranking-list"', $html)[1] ?? $html);
        $this->assertStringContainsString('配給収入と興行収入は別の指標', $html);
        $this->assertStringContainsString('list-item', $html);
        $this->assertStringContainsString('list-rank-unranked', $html);
        $this->assertStringNotContainsString('top-card', $html);
        $this->assertStringNotContainsString('series-release-year', $html);
    }

    public function test_series_detail_ranks_by_box_office_and_parks_rentals(): void
    {
        $html = $this->invoke('seriesDetailHtml', [
            'slug' => 'mix',
            'name' => '混合',
            'path' => '/series/mix/',
            'filmCount' => 4,
            'knownCount' => 3,
            'totalYen' => 11_100_000_000,
            'hasRentals' => true,
            'sumRentalsYen' => 20_000_000_000,
            'indexable' => true,
            'films' => [[
                'id' => 'old-bo',
                'title' => '古い興収作',
                'releaseYear' => 1990,
                'boxOffice' => 1_100_000_000,
                'metric' => 'box_office',
                'href' => '/movies/old-bo/',
            ], [
                'id' => 'top-bo',
                'title' => '最高興収作',
                'releaseYear' => 2020,
                'boxOffice' => 10_000_000_000,
                'metric' => 'box_office',
                'href' => '/movies/top-bo/',
            ], [
                'id' => 'rental',
                'title' => '配収のみ',
                'releaseYear' => 1980,
                'boxOffice' => 20_000_000_000,
                'metric' => 'distributor_rentals',
                'href' => '/movies/rental/',
            ], [
                'id' => 'unknown',
                'title' => '数字なし',
                'releaseYear' => 2024,
                'boxOffice' => null,
                'metric' => 'box_office',
                'href' => '/movies/unknown/',
            ]],
        ], [
            'region' => 'japan',
            'indexPath' => '/series/',
        ]);

        $this->assertStringContainsString('<article class="list-item rank-1 is-japan" data-movie-id="top-bo"', $html);
        $this->assertStringContainsString('<article class="list-item rank-2 is-japan" data-movie-id="old-bo"', $html);
        $this->assertStringNotContainsString('top-card', $html);
        $this->assertStringNotContainsString('series-release-year', $html);
        $this->assertStringContainsString('data-movie-id="rental"', explode('id="series-ranking-list"', $html)[1]);
        $this->assertStringContainsString('（配給収入）', $html);
        $this->assertStringNotContainsString('data-movie-id="unknown"', $html);
        $this->assertStringNotContainsString('数字なし', $html);
        $podiumPos = strpos($html, '最高興収作');
        $rentalPos = strpos($html, '配収のみ');
        $this->assertNotFalse($podiumPos);
        $this->assertGreaterThan($podiumPos, $rentalPos);
        $this->assertStringContainsString('"position":1', $html);
        $this->assertStringContainsString('"name":"最高興収作"', $html);
    }

    public function test_world_catalog_groups_collection_films_without_changing_rankings(): void
    {
        $catalog = SeriesCatalog::world([
            [
                'id' => 'tmdb-671',
                'title' => 'ハリー・ポッターと賢者の石',
                'rank' => 12,
                'inRanking' => true,
                'boxOffice' => 974_755_371,
                'releaseYear' => 2001,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
            [
                'id' => 'tmdb-672',
                'title' => 'ハリー・ポッターと秘密の部屋',
                'rank' => 30,
                'inRanking' => true,
                'boxOffice' => 879_000_000,
                'releaseYear' => 2002,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
        ], [[
            'id' => 'tmdb-675',
            'title' => 'ハリー・ポッターと炎のゴブレット',
            'rank' => null,
            'inRanking' => false,
            'boxOffice' => 896_000_000,
            'releaseYear' => 2005,
            'collectionId' => 1241,
            'collectionName' => 'ハリー・ポッター コレクション',
        ]]);

        $this->assertCount(1, $catalog['series']);
        $hp = $catalog['series'][0];
        $slug = SeriesSlug::fromName('ハリー・ポッター コレクション');
        $this->assertSame('/series/global/'.$slug.'/', $hp['path']);
        $this->assertCount(3, $hp['films']);
        $this->assertSame(['tmdb-671', 'tmdb-675', 'tmdb-672'], array_column($hp['films'], 'id'));
        $this->assertSame(12, $hp['films'][0]['rank']);
        $this->assertNull($hp['films'][1]['rank']);
        $this->assertSame(30, $hp['films'][2]['rank']);
        $this->assertSame(974_755_371 + 879_000_000 + 896_000_000, $hp['totalUsd']);
        $this->assertSame('/series/global/'.$slug.'/', $catalog['movieLinks']['tmdb-671']['href']);
    }

    public function test_world_catalog_dedupes_ranking_and_collection_duplicates(): void
    {
        $catalog = SeriesCatalog::world([
            [
                'id' => 'tmdb-671',
                'tmdbId' => 671,
                'title' => 'ハリー・ポッターと賢者の石',
                'rank' => 12,
                'inRanking' => true,
                'boxOffice' => 974_755_371,
                'posterUrl' => '/posters/hp.jpg',
                'releaseYear' => 2001,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
        ], [
            [
                'id' => 'tmdb-671-rerelease',
                'tmdbId' => 671,
                'title' => 'ハリー・ポッターと賢者の石',
                'rank' => null,
                'inRanking' => false,
                'boxOffice' => 0,
                'releaseYear' => 2001,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
            [
                'id' => 'tmdb-999001',
                'tmdbId' => 999001,
                'title' => 'ハリー・ポッターと賢者の石 (映画)',
                'rank' => null,
                'inRanking' => false,
                'boxOffice' => 10,
                'releaseYear' => 2001,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
        ]);

        $hp = $catalog['series'][0];
        $this->assertCount(1, $hp['films']);
        $this->assertSame(1, $hp['filmCount']);
        $this->assertSame(1, $hp['knownCount']);
        $this->assertSame('tmdb-671', $hp['films'][0]['id']);
        $this->assertSame(12, $hp['films'][0]['rank']);
        $this->assertSame(974_755_371, $hp['films'][0]['boxOffice']);
        $this->assertSame(974_755_371, $hp['totalUsd']);
        $this->assertArrayHasKey('tmdb-671', $catalog['movieLinks']);
        $this->assertArrayNotHasKey('tmdb-671-rerelease', $catalog['movieLinks']);
    }

    public function test_japan_harry_potter_snapshot_is_deduped_and_no_series_has_duplicate_films(): void
    {
        $raw = collect(JapanSeriesSnapshot::series())->firstWhere('name', 'ハリー・ポッター');
        $this->assertIsArray($raw);
        $rawStone = array_values(array_filter(
            $raw['films'],
            fn (array $film) => str_contains((string) $film['title'], '賢者の石')
        ));
        $this->assertNotSame([], $rawStone);

        $catalog = SeriesCatalog::japan([]);
        $hp = collect($catalog['series'])->firstWhere('name', 'ハリー・ポッター');
        $this->assertIsArray($hp);
        $stone = array_values(array_filter(
            $hp['films'],
            fn (array $film) => str_contains((string) ($film['title'] ?? ''), '賢者の石')
        ));
        $this->assertCount(1, $stone);
        $this->assertSame(count($hp['films']), $hp['filmCount']);

        foreach (array_merge($catalog['series'], SeriesCatalog::world([])['series']) as $series) {
            $this->assertSeriesFilmsAreUnique($series);
        }
        $extraIds = array_map(fn (array $movie) => (string) ($movie['id'] ?? ''), $catalog['extraMovies']);
        $this->assertSame($extraIds, array_values(array_unique($extraIds)));
    }

    public function test_snapshot_drops_music_shorts_and_duplicate_kraven(): void
    {
        $dceu = collect(JapanSeriesSnapshot::series())->firstWhere('name', 'DCエクステンデッド・ユニバース');
        $this->assertIsArray($dceu);
        $dceuTitles = array_column($dceu['films'], 'title');
        $this->assertGreaterThanOrEqual(10, count($dceuTitles));
        $this->assertLessThanOrEqual(20, count($dceuTitles));
        foreach ($dceuTitles as $title) {
            $this->assertFalse(SeriesFilmMatcher::isNonFilmRowTitle($title), $title);
            $this->assertStringNotContainsString('Heathens', $title);
            $this->assertStringNotContainsString('サウンドトラック', $title);
            $this->assertStringNotContainsString('soundtrack', mb_strtolower($title));
        }

        $fast = collect(JapanSeriesSnapshot::series())->firstWhere('name', 'ワイルド・スピード');
        $this->assertIsArray($fast);
        $fastTitles = array_column($fast['films'], 'title');
        $this->assertTrue((bool) array_filter($fastTitles, fn (string $title) => str_contains($title, 'ジェットブレイク')));
        $this->assertTrue((bool) array_filter($fastTitles, fn (string $title) => str_contains($title, 'ファイヤーブースト')));
        foreach ($fastTitles as $title) {
            $this->assertFalse(SeriesFilmMatcher::isNonFilmRowTitle($title), $title);
            $this->assertStringNotContainsString('Prelude', $title);
            $this->assertStringNotContainsString('バンドレロス', $title);
            $this->assertStringNotContainsString('soundtrack', mb_strtolower($title));
        }

        $ssu = collect(JapanSeriesSnapshot::series())->firstWhere('name', 'ソニーズ・スパイダーマン・ユニバース');
        $this->assertIsArray($ssu);
        $kraven = array_values(array_filter(
            $ssu['films'],
            fn (array $film) => str_contains((string) $film['title'], 'クレイヴン')
        ));
        $this->assertCount(1, $kraven);

        $catalog = SeriesCatalog::japan([]);
        $hero = collect($catalog['series'])->firstWhere('name', 'HERO');
        $this->assertIsArray($hero);
        $heroTitles = array_column($hero['films'], 'title');
        $this->assertSame(count($heroTitles), count(array_unique($heroTitles)));
        if (count($hero['films']) > 1) {
            foreach ($hero['films'] as $film) {
                $this->assertMatchesRegularExpression('/HERO \(\d{4}\)/', (string) $film['title']);
            }
        }
    }

    public function test_film_matcher_links_conan_titles_to_ranking_rows(): void
    {
        $matched = SeriesFilmMatcher::match([
            'title' => '名探偵コナン 100万ドルの五稜星',
            'shortTitle' => '100万ドルの五稜星',
            'releaseYear' => 2024,
        ], [[
            'id' => 'jp-tmdb-1',
            'title' => '名探偵コナン 100万ドルの五稜星',
            'releaseYear' => 2024,
            'rank' => 5,
            'boxOffice' => 15_800_000_000,
        ]]);

        $this->assertSame('jp-tmdb-1', $matched['id']);
    }

    public function test_footer_and_ranking_html_include_the_series_entry_point(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $footer = $this->invoke('siteFooterInnerHtml');
        $this->assertStringContainsString('href="/series/"', $footer);
        $this->assertStringContainsString('シリーズ興行収入', $footer);

        $ranking = $this->invoke('rankingStaticHtml', [[
            'id' => 'movie-1',
            'rank' => 1,
            'title' => '作品1',
            'revenue' => '1億円',
        ]], true, null);
        $this->assertStringContainsString('href="/series/"', $ranking);
        $this->assertStringContainsString('日本映画興行収入ランキング', $ranking);
    }

    public function test_unreleased_films_are_labeled_excluded_from_totals_and_off_the_podium(): void
    {
        Carbon::setTestNow('2026-11-14T12:00:00+09:00');
        try {
            $catalog = SeriesCatalog::world([[
                'id' => 'tmdb-1',
                'title' => '公開済み',
                'rank' => 10,
                'inRanking' => true,
                'boxOffice' => 500_000_000,
                'releaseDate' => '2020-01-01',
                'releaseYear' => 2020,
                'updatedAt' => '2026-10-01T03:00:00+09:00',
                'collectionId' => 86837,
                'collectionName' => '戦狼 Collection',
            ]], [[
                'id' => 'tmdb-2',
                'title' => '続編',
                'rank' => null,
                'inRanking' => false,
                'boxOffice' => 0,
                'releaseDate' => '2031-12-17',
                'releaseYear' => 2031,
                'updatedAt' => '2026-11-14T03:00:00+09:00',
                'collectionId' => 86837,
                'collectionName' => '戦狼 Collection',
            ]]);
        } finally {
            Carbon::setTestNow();
        }

        $series = $catalog['series'][0];
        $this->assertSame(500_000_000, $series['totalUsd']);
        $this->assertSame(1, $series['knownCount']);
        $this->assertSame(0, $series['excludedUnknownCount']);
        $this->assertCount(1, $series['films']);
        $this->assertSame('tmdb-1', $series['films'][0]['id']);
        $this->assertArrayNotHasKey('tmdb-2', $catalog['movieLinks']);

        $html = $this->invoke('seriesDetailHtml', $series, $catalog);
        $this->assertStringContainsString('<article class="list-item rank-1" data-movie-id="tmdb-1"', $html);
        $this->assertStringNotContainsString('top-card', $html);
        $this->assertStringNotContainsString('data-movie-id="tmdb-2"', $html);
        $this->assertStringNotContainsString('公開予定', $html);
        $this->assertStringNotContainsString('不明1作を除く', $html);
        $this->assertStringContainsString('5.00億ドル', $html);
        $this->assertStringContainsString('1作品、合計5.00億ドル', $html);
    }

    public function test_world_collection_slugs_are_unique_and_not_generic(): void
    {
        $catalog = SeriesCatalog::world([
            [
                'id' => 'tmdb-ww1',
                'title' => '戦狼',
                'rank' => 1,
                'inRanking' => true,
                'boxOffice' => 100,
                'collectionId' => 86837,
                'collectionName' => '戦狼 Collection',
            ],
            [
                'id' => 'tmdb-c1',
                'title' => 'Generic A',
                'rank' => 2,
                'inRanking' => true,
                'boxOffice' => 90,
                'collectionId' => 11,
                'collectionName' => 'Collection',
            ],
            [
                'id' => 'tmdb-c2',
                'title' => 'Generic B',
                'rank' => 3,
                'inRanking' => true,
                'boxOffice' => 80,
                'collectionId' => 22,
                'collectionName' => 'Collection',
            ],
        ]);

        $paths = array_column($catalog['series'], 'path');
        $slugs = array_column($catalog['series'], 'slug');
        $this->assertCount(3, $paths);
        $this->assertSame($paths, array_unique($paths));
        $this->assertSame($slugs, array_unique($slugs));
        $this->assertSame('/series/global/戦狼-Collection/', $catalog['series'][0]['path']);
        $this->assertNotContains('/series/global/collection/', $paths);
        foreach ($slugs as $slug) {
            $this->assertNotSame('', $slug);
            $this->assertFalse(SeriesSlug::isGeneric((string) $slug));
        }
        $this->assertContains('tmdb-collection-11', $slugs);
        $this->assertContains('tmdb-collection-22', $slugs);
    }

    public function test_all_series_paths_are_unique_across_japan_and_world(): void
    {
        $japan = SeriesCatalog::japan([]);
        $world = SeriesCatalog::world([
            [
                'id' => 'tmdb-ww1',
                'title' => '戦狼',
                'rank' => 1,
                'inRanking' => true,
                'boxOffice' => 100,
                'collectionId' => 86837,
                'collectionName' => '戦狼 Collection',
            ],
            [
                'id' => 'tmdb-hp',
                'title' => 'ハリー・ポッターと賢者の石',
                'rank' => 2,
                'inRanking' => true,
                'boxOffice' => 90,
                'collectionId' => 1241,
                'collectionName' => 'ハリー・ポッター コレクション',
            ],
        ]);

        $paths = [];
        foreach ([$japan, $world] as $catalog) {
            $paths[] = $catalog['indexPath'];
            foreach ($catalog['series'] as $series) {
                $this->assertNotSame('', $series['slug']);
                $this->assertNotSame('/series/global/collection/', $series['path']);
                $paths[] = $series['path'];
            }
        }
        $this->assertSame($paths, array_values(array_unique($paths)));
        $this->assertContains('/series/global/', $paths);
        $this->assertNotContains('global', array_column($japan['series'], 'slug'));
    }

    public function test_lastmod_and_date_modified_never_use_release_dates_or_the_future(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        Carbon::setTestNow('2026-11-14T12:00:00+09:00');
        $dir = sys_get_temp_dir().'/mubiran-series-future-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $worldCatalog = [
            'region' => 'global',
            'indexPath' => '/series/global/',
            'retrievedAt' => null,
            'series' => [[
                'slug' => '戦狼-Collection',
                'name' => '戦狼 Collection',
                'path' => '/series/global/戦狼-Collection/',
                'indexable' => true,
                'films' => [[
                    'id' => 'tmdb-1',
                    'title' => '戦狼',
                    'boxOffice' => 100,
                    'releaseDate' => '2015-04-02',
                    'updatedAt' => '2026-10-01T03:00:00+09:00',
                    'unreleased' => false,
                ], [
                    'id' => 'tmdb-2',
                    'title' => '続編',
                    'boxOffice' => null,
                    'releaseDate' => '2031-12-17',
                    'updatedAt' => '2026-11-14T03:00:00+09:00',
                    'unreleased' => true,
                ]],
            ]],
        ];
        $japanCatalog = [
            'region' => 'japan',
            'indexPath' => '/series/',
            'retrievedAt' => '2026-11-14',
            'series' => [[
                'slug' => 'ドラえもん',
                'name' => 'ドラえもん',
                'path' => '/series/ドラえもん/',
                'indexable' => true,
                'films' => [[
                    'id' => 'jp-series-old',
                    'title' => '映画ドラえもん のび太の絵世界物語',
                    'boxOffice' => 4_610_000_000,
                    'releaseDate' => '2025-03-07',
                    'updatedAt' => '2026-11-14T00:00:00+09:00',
                    'unreleased' => false,
                    'href' => '/movies/jp-series-old/',
                ], [
                    'id' => 'jp-series-future',
                    'title' => '映画ドラえもん のび太の蒸気時間車',
                    'boxOffice' => null,
                    'releaseDate' => '2027-03-05',
                    'updatedAt' => '2026-11-14T00:00:00+09:00',
                    'unreleased' => true,
                    'href' => '/movies/jp-series-future/',
                ]],
            ]],
        ];

        try {
            $this->invoke(
                'exportSitemap',
                $dir,
                [[
                    'id' => 'tmdb-1',
                    'updatedAt' => '2026-10-01T03:00:00+09:00',
                    'releaseDate' => '2015-04-02',
                ]],
                [],
                [[
                    'id' => 'jp-series-future',
                    'updatedAt' => '2026-11-14T00:00:00+09:00',
                    'releaseDate' => '2027-03-05',
                    'boxOffice' => 0,
                    'inRanking' => false,
                    'indexable' => false,
                ], [
                    'id' => 'tmdb-2',
                    'updatedAt' => '2026-11-14T03:00:00+09:00',
                    'releaseDate' => '2031-12-17',
                    'boxOffice' => 0,
                    'inRanking' => false,
                    'indexable' => false,
                ]],
                '2026-10-01 03:00:00',
                '2026-11-14 00:00:00',
                [],
                [],
                array_merge(
                    $this->invoke('seriesSitemapEntries', $japanCatalog),
                    $this->invoke('seriesSitemapEntries', $worldCatalog),
                ),
            );
            $xml = (string) file_get_contents($dir.'/sitemap.xml');
            $detail = $this->invoke('seriesDetailHtml', $japanCatalog['series'][0], $japanCatalog);
            $worldDetail = $this->invoke('seriesDetailHtml', $worldCatalog['series'][0], $worldCatalog);
            $extraPage = $this->invoke('moviePageHtml', [
                'id' => 'jp-series-future',
                'title' => '映画ドラえもん のび太の蒸気時間車',
                'revenue' => '公開予定',
                'boxOffice' => 0,
                'rank' => null,
                'inRanking' => false,
                'releaseDate' => '2027-03-05',
                'updatedAt' => '2026-11-14T00:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], true, sys_get_temp_dir(), []);
            $worldExtraPage = $this->invoke('moviePageHtml', [
                'id' => 'tmdb-2',
                'title' => '続編',
                'revenue' => '公開予定',
                'boxOffice' => 0,
                'rank' => null,
                'inRanking' => false,
                'releaseDate' => '2031-12-17',
                'updatedAt' => '2026-11-14T03:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], false, sys_get_temp_dir(), []);
        } finally {
            Carbon::setTestNow();
            @unlink($dir.'/sitemap.xml');
            @rmdir($dir);
        }

        $this->assertStringNotContainsString('2031-12-17', $xml);
        $this->assertStringNotContainsString('2027-03-05', $xml);
        $this->assertStringContainsString('<loc>https://mubiran.com/series/ドラえもん/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://mubiran.com/series/global/戦狼-Collection/</loc>', $xml);
        $this->assertSame('2026-10-01T03:00:00+09:00', $this->invoke('seriesLastmod', $worldCatalog['series'][0], null));
        $this->assertSame('2026-11-14T00:00:00+09:00', $this->invoke('seriesLastmod', $japanCatalog['series'][0], '2026-11-14T00:00:00+09:00'));

        $now = '2026-11-14T12:00:00+09:00';
        preg_match_all('#<lastmod>(.*?)</lastmod>#', $xml, $lastmods);
        foreach ($lastmods[1] as $lastmod) {
            $this->assertLessThanOrEqual($now, $lastmod, $lastmod);
        }
        foreach ([$detail, $worldDetail, $extraPage, $worldExtraPage] as $html) {
            preg_match_all('#"dateModified":"([^"]+)"#', $html, $dates);
            foreach ($dates[1] as $dateModified) {
                $this->assertLessThanOrEqual($now, $dateModified, $dateModified);
                $this->assertStringNotContainsString('2031', $dateModified);
                $this->assertStringNotContainsString('2027-03-05', $dateModified);
            }
        }
        $this->assertStringContainsString('"dateModified":"2026-11-14T00:00:00+09:00"', $detail);
        $this->assertStringContainsString('"dateModified":"2026-10-01T03:00:00+09:00"', $worldDetail);
        $this->assertStringContainsString('"dateModified":"2026-11-14T00:00:00+09:00"', $extraPage);
        $this->assertStringContainsString('"dateModified":"2026-11-14T03:00:00+09:00"', $worldExtraPage);
    }

    public function test_japan_series_total_is_the_sum_of_listed_known_films(): void
    {
        $catalog = SeriesCatalog::japan([]);
        $names = [
            'キングダム',
            'ゴジラ',
            'ジュラシック・パーク',
            '鬼滅の刃',
            'ONE PIECE',
            '海猿',
            'インディ・ジョーンズ',
            'ファンタスティック・ビースト',
            'ロバート・ラングドン',
            'インサイド・ヘッド',
            '名探偵コナン',
        ];

        $totals = array_map(
            fn (array $series): int => (int) ($series['totalYen'] ?? 0),
            $catalog['series']
        );
        $sorted = $totals;
        rsort($sorted);
        $this->assertSame($sorted, $totals);

        foreach ($names as $name) {
            $series = collect($catalog['series'])->firstWhere('name', $name);
            $this->assertIsArray($series, $name);
            $this->assertSame(
                $this->sumListedBoxOfficeYen($series),
                $series['totalYen'],
                $name
            );
            $this->assertSame(0, $series['excludedUnknownCount'], $name);
            $this->assertSame($series['filmCount'], $series['knownCount'], $name);
            if ($name !== 'インサイド・ヘッド' && is_numeric($series['reportedTotalYen'] ?? null)) {
                $this->assertNotSame(
                    (int) $series['reportedTotalYen'],
                    $series['totalYen'],
                    $name.' should not use the Wikipedia series total'
                );
            }
        }

        $insideOut = collect($catalog['series'])->firstWhere('name', 'インサイド・ヘッド');
        $this->assertIsArray($insideOut);
        $insideOut2015 = collect($insideOut['films'])->first(
            fn (array $film) => (string) ($film['title'] ?? '') === 'インサイド・ヘッド'
        );
        $insideOut2 = collect($insideOut['films'])->first(
            fn (array $film) => str_contains((string) ($film['title'] ?? ''), 'インサイド・ヘッド2')
        );
        $this->assertIsArray($insideOut2015);
        $this->assertIsArray($insideOut2);

        $kingdom = collect($catalog['series'])->firstWhere('name', 'キングダム');
        $godzilla = collect($catalog['series'])->firstWhere('name', 'ゴジラ');
        $jurassic = collect($catalog['series'])->firstWhere('name', 'ジュラシック・パーク');
        $this->assertSame(31_420_000_000, $kingdom['totalYen']);
        $this->assertSame(15_900_000_000, $godzilla['totalYen']);
        $this->assertSame(51_400_000_000, $jurassic['totalYen']);
        $this->assertSame(4_040_000_000, $insideOut2015['boxOffice']);
        $this->assertSame(5_360_000_000, $insideOut2['boxOffice']);
        $this->assertSame(9_400_000_000, $insideOut['totalYen']);

        $onePiece = collect($catalog['series'])->firstWhere('name', 'ONE PIECE');
        $this->assertSame(0, $onePiece['excludedUnknownCount']);
        $html = $this->invoke('seriesDetailHtml', $onePiece, $catalog);
        $this->assertStringNotContainsString('不明', explode('id="series-ranking-list"', $html)[1] ?? '');
        $this->assertStringNotContainsString('公開予定', $html);
        $this->assertStringNotContainsString('作を除く', $html);
        $this->assertStringContainsString('"description":"ONE PIECEシリーズの日本興行収入。', $html);
        $this->assertStringContainsString($onePiece['filmCount'].'作品、合計'.$this->yenText($onePiece['totalYen']), $html);

        $index = $this->invoke('seriesIndexHtml', $catalog, [
            'region' => 'global',
            'indexPath' => '/series/global/',
            'series' => [],
        ]);
        $this->assertStringNotContainsString('作を除く', $index);
        $this->assertStringContainsString($this->yenText($kingdom['totalYen']), $index);
        $this->assertStringNotContainsString($this->yenText($kingdom['reportedTotalYen']), $index);
    }

    public function test_series_total_is_unknown_when_every_listed_film_lacks_a_figure(): void
    {
        $catalog = SeriesCatalog::world([[
            'id' => 'tmdb-u1',
            'title' => '数字なし',
            'rank' => 1,
            'inRanking' => true,
            'boxOffice' => 0,
            'releaseDate' => '2020-01-01',
            'collectionId' => 101,
            'collectionName' => '不明シリーズ',
        ], [
            'id' => 'tmdb-u2',
            'title' => '続編も不明',
            'rank' => 2,
            'inRanking' => true,
            'boxOffice' => 0,
            'releaseDate' => '2021-01-01',
            'collectionId' => 101,
            'collectionName' => '不明シリーズ',
        ]]);

        $this->assertSame([], $catalog['series']);
        $this->assertSame([], $catalog['movieLinks']);
    }

    public function test_zero_film_series_are_omitted_from_catalog_index_pages_and_sitemap(): void
    {
        $snapshot = JapanSeriesSnapshot::series();
        $emptyNames = [];
        foreach ($snapshot as $row) {
            if (! is_array($row) || ($row['films'] ?? []) !== []) {
                continue;
            }
            $emptyNames[] = (string) ($row['name'] ?? '');
        }
        $this->assertGreaterThanOrEqual(20, count($emptyNames));

        $catalog = SeriesCatalog::japan([]);
        $catalogNames = array_column($catalog['series'], 'name');
        $this->assertNotEmpty($catalog['series']);
        foreach ($catalog['series'] as $series) {
            $this->assertTrue(SeriesCatalog::hasListedFilms($series), (string) ($series['name'] ?? ''));
        }
        foreach ($emptyNames as $name) {
            if ($name === '') {
                continue;
            }
            $this->assertNotContains($name, $catalogNames);
        }
        $this->assertLessThan(count($snapshot) - count($emptyNames), count($catalog['series']));

        $dir = sys_get_temp_dir().'/mubiran-series-empty-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $mixed = [
            'region' => 'japan',
            'indexPath' => '/series/',
            'retrievedAt' => '2026-11-14',
            'sourceUrl' => 'https://ja.wikipedia.org/wiki/日本歴代興行成績上位の映画一覧',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja',
            'series' => [[
                'slug' => 'キングダム',
                'name' => 'キングダム',
                'path' => '/series/キングダム/',
                'filmCount' => 5,
                'totalYen' => 31_420_000_000,
                'excludedUnknownCount' => 0,
                'indexable' => true,
                'films' => [[
                    'title' => 'キングダム',
                    'boxOffice' => 5_730_000_000,
                    'metric' => 'box_office',
                    'kind' => 'theatrical',
                    'href' => '/movies/jp-kingdom/',
                ]],
            ], [
                'slug' => 'ロッキー',
                'name' => 'ロッキー',
                'path' => '/series/ロッキー/',
                'filmCount' => 0,
                'totalYen' => null,
                'excludedUnknownCount' => 0,
                'indexable' => false,
                'films' => [],
            ]],
        ];
        $other = ['region' => 'global', 'indexPath' => '/series/global/', 'series' => []];

        try {
            $this->invoke('exportSeriesPages', $dir, $mixed, $other);
            $index = (string) file_get_contents($dir.'/series/index.html');
            $entries = $this->invoke('seriesSitemapEntries', $mixed);
            $keptPage = is_file($dir.'/series/キングダム/index.html');
            $emptyPage = is_file($dir.'/series/ロッキー/index.html');
            $emptyJson = is_file($dir.'/data/series/japan/ロッキー.json');
        } finally {
            $this->removeDirectory($dir);
        }

        $this->assertTrue($keptPage);
        $this->assertFalse($emptyPage);
        $this->assertFalse($emptyJson);
        $this->assertStringContainsString('href="/series/キングダム/"', $index);
        $this->assertStringNotContainsString('href="/series/ロッキー/"', $index);
        $this->assertStringNotContainsString('ロッキー', $index);
        $locs = array_column($entries, 'loc');
        $this->assertTrue(collect($locs)->contains(fn (string $loc): bool => str_contains($loc, '/series/キングダム/')));
        $this->assertFalse(collect($locs)->contains(fn (string $loc): bool => str_contains($loc, '/series/ロッキー/')));
        foreach ($catalog['movieLinks'] as $link) {
            $this->assertContains($link['name'], $catalogNames);
        }
    }

    public function test_unknown_and_unreleased_films_are_hidden_and_empty_series_are_omitted(): void
    {
        $catalog = SeriesCatalog::japan([]);
        $catalogNames = array_column($catalog['series'], 'name');
        $hiddenSeries = [
            '男はつらいよ',
            '仮面ライダー',
            'DCエクステンデッド・ユニバース',
            'トランスフォーマー',
            'バイオハザード',
            'ソニーズ・スパイダーマン・ユニバース',
            '銀魂（アニメ）',
            'ワイルド・スピード',
        ];
        foreach ($hiddenSeries as $name) {
            $this->assertNotContains($name, $catalogNames, $name);
            $this->assertFalse(
                collect($catalog['movieLinks'])->contains(fn (array $link): bool => ($link['name'] ?? '') === $name),
                $name
            );
        }
        foreach ($catalog['series'] as $series) {
            $this->assertGreaterThan(0, (int) $series['filmCount'], (string) ($series['name'] ?? ''));
            $this->assertSame(0, (int) $series['excludedUnknownCount'], (string) ($series['name'] ?? ''));
            foreach ($series['films'] as $film) {
                $this->assertTrue(
                    SeriesCatalog::filmHasDisplayableAmount($film),
                    (string) ($series['name'] ?? '').' '.(string) ($film['title'] ?? '')
                );
            }
            $html = $this->invoke('seriesDetailHtml', $series, $catalog);
            $this->assertStringNotContainsString('作を除く', $html, (string) ($series['name'] ?? ''));
            $this->assertStringNotContainsString('upcoming-badge-card', $html, (string) ($series['name'] ?? ''));
            $list = explode('id="series-ranking-list"', $html)[1] ?? '';
            $this->assertStringNotContainsString('>不明<', $list, (string) ($series['name'] ?? ''));
            $this->assertStringNotContainsString('公開予定', $list, (string) ($series['name'] ?? ''));
        }

        $index = $this->invoke('seriesIndexHtml', $catalog, [
            'region' => 'global',
            'indexPath' => '/series/global/',
            'series' => [],
        ]);
        $this->assertStringNotContainsString('作を除く', $index);
        foreach ($hiddenSeries as $name) {
            $this->assertStringNotContainsString($name, $index);
        }

        $entries = $this->invoke('seriesSitemapEntries', $catalog);
        $locs = array_column($entries, 'loc');
        foreach ($hiddenSeries as $name) {
            $this->assertFalse(
                collect($locs)->contains(fn (string $loc): bool => str_contains($loc, rawurlencode($name))),
                $name
            );
        }
    }

    public function test_allocate_reported_remainder_fills_the_one_missing_japan_gross(): void
    {
        $films = SeriesCatalog::allocateReportedRemainder([
            [
                'title' => 'インサイド・ヘッド',
                'boxOffice' => null,
                'metric' => 'box_office',
                'unreleased' => false,
            ],
            [
                'title' => 'インサイド・ヘッド2',
                'boxOffice' => 5_360_000_000,
                'metric' => 'box_office',
                'unreleased' => false,
            ],
        ], 9_400_000_000);

        $this->assertSame(4_040_000_000, $films[0]['boxOffice']);
        $this->assertSame('40.4億円', $films[0]['revenue']);
        $this->assertSame(5_360_000_000, $films[1]['boxOffice']);
    }

    public function test_series_page_amounts_match_ranking_movies(): void
    {
        $ranking = [
            [
                'id' => 'jp-tmdb-1022789',
                'title' => 'インサイド・ヘッド2',
                'releaseYear' => 2024,
                'rank' => 40,
                'inRanking' => true,
                'boxOffice' => 5_360_000_000,
            ],
            [
                'id' => 'jp-tmdb-kingdom',
                'title' => 'キングダム',
                'releaseYear' => 2019,
                'rank' => 20,
                'inRanking' => true,
                'boxOffice' => 5_730_000_000,
            ],
            [
                'id' => 'jp-tmdb-godzilla',
                'title' => 'ゴジラ-1.0',
                'releaseYear' => 2023,
                'rank' => 30,
                'inRanking' => true,
                'boxOffice' => 7_650_000_000,
            ],
        ];

        $catalog = SeriesCatalog::japan($ranking);
        $this->assertSame([], SeriesCatalog::rankingAmountMismatches($catalog, $ranking));

        $insideOut = collect($catalog['series'])->firstWhere('name', 'インサイド・ヘッド');
        $this->assertIsArray($insideOut);
        $insideOut2 = collect($insideOut['films'])->first(
            fn (array $film) => str_contains((string) ($film['title'] ?? ''), 'インサイド・ヘッド2')
        );
        $insideOut2015 = collect($insideOut['films'])->first(
            fn (array $film) => (string) ($film['title'] ?? '') === 'インサイド・ヘッド'
        );
        $this->assertSame(5_360_000_000, $insideOut2['boxOffice']);
        $this->assertSame(4_040_000_000, $insideOut2015['boxOffice']);
        $this->assertSame(9_400_000_000, $insideOut['totalYen']);
        $this->assertSame('/movies/jp-tmdb-1022789/', $insideOut2['href']);
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function sumListedBoxOfficeYen(array $series): ?int
    {
        $sum = 0;
        foreach ($series['films'] ?? [] as $film) {
            if (! is_array($film) || ! empty($film['unreleased']) || empty($film['boxOffice'])) {
                continue;
            }
            if (($film['metric'] ?? '') === 'distributor_rentals') {
                continue;
            }
            $sum += (int) $film['boxOffice'];
        }

        return $sum > 0 ? $sum : null;
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function countUnknownOrUnreleased(array $series): int
    {
        $count = 0;
        foreach ($series['films'] ?? [] as $film) {
            if (! is_array($film) || ! empty($film['unreleased']) || empty($film['boxOffice'])) {
                $count++;
            }
        }

        return $count;
    }

    private function yenText(mixed $yen): string
    {
        if (! is_numeric($yen) || (int) $yen <= 0) {
            return '不明';
        }

        return number_format(((int) $yen) / 100000000, 1).'億円';
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function assertSeriesFilmsAreUnique(array $series): void
    {
        $tmdbIds = [];
        $titles = [];
        foreach ($series['films'] ?? [] as $film) {
            $tmdbId = SeriesFilmMatcher::tmdbId($film);
            if ($tmdbId !== null) {
                $this->assertArrayNotHasKey(
                    $tmdbId,
                    $tmdbIds,
                    ($series['name'] ?? '').' has duplicate TMDB id '.$tmdbId
                );
                $tmdbIds[$tmdbId] = true;
            }
            $title = SeriesFilmMatcher::identityCompact((string) ($film['title'] ?? ''));
            $year = SeriesFilmMatcher::year($film);
            if ($title === '' || $year === null) {
                continue;
            }
            $key = $title.'|'.$year;
            $this->assertArrayNotHasKey(
                $key,
                $titles,
                ($series['name'] ?? '').' has duplicate title '.$key
            );
            $titles[$key] = true;
        }
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ExportStaticSite::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->app->make(ExportStaticSite::class), ...$arguments);
    }

    private function ensureViteManifest(): void
    {
        $dir = public_path('build');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir.'/manifest.json';
        if (! is_file($path)) {
            file_put_contents($path, json_encode([
                'resources/js/static-site.js' => [
                    'file' => 'assets/static-site-test.js',
                    'css' => ['assets/static-site-test.css'],
                ],
            ]));
        }
    }
}
