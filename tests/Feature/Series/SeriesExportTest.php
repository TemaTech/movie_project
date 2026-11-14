<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Services\Series\SeriesCatalog;
use App\Services\Series\SeriesFilmMatcher;
use App\Services\Series\SeriesSlug;
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

        $this->assertGreaterThanOrEqual(86, count($catalog['series']));
        $conan = collect($catalog['series'])->firstWhere('name', '名探偵コナン');
        $this->assertIsArray($conan);
        $theatrical = array_values(array_filter(
            $conan['films'],
            fn (array $film) => ($film['kind'] ?? '') === 'theatrical'
        ));
        $this->assertCount(29, $theatrical);
        $this->assertSame('/series/名探偵コナン/', $conan['path']);
        $this->assertNotNull($conan['totalYen']);
        $this->assertStringContainsString('時計じかけの摩天楼', $theatrical[0]['title']);
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

        $detail = $this->invoke('seriesDetailHtml', $catalog['series'][0], $catalog);
        $this->assertStringContainsString('名探偵コナンのシリーズ興行収入', $detail);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/名探偵コナン/"', $detail);
        $this->assertStringContainsString('時計じかけの摩天楼', $detail);
        $this->assertStringContainsString('11.0億円', $detail);
        $this->assertStringNotContainsString('配給収入と興行収入は別の指標', $detail);
        $this->assertStringContainsString('ItemList', $detail);
        $this->assertStringContainsString('Wikipedia', $detail);
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
                        'films' => [],
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
        $this->assertStringContainsString('<lastmod>2026-11-14T00:00:00+09:00</lastmod>', $xml);
        $this->assertStringContainsString('<loc>https://mubiran.com/</loc>', $xml);
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
        $this->assertSame(12, $hp['films'][0]['rank']);
        $this->assertNull($hp['films'][2]['rank']);
        $this->assertSame(974_755_371 + 879_000_000 + 896_000_000, $hp['totalUsd']);
        $this->assertSame('/series/global/'.$slug.'/', $catalog['movieLinks']['tmdb-671']['href']);
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
