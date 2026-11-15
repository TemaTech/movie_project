<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Services\Series\BrandCatalog;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class BrandExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureViteManifest();
    }

    public function test_brand_catalog_assigns_ranking_films_and_skips_distribution_only(): void
    {
        $japan = BrandCatalog::japan($this->japanRankingFixture());
        $slugs = array_column($japan['series'], 'slug');
        $this->assertEqualsCanonicalizing(['pixar', 'disney-animation'], $slugs);

        $pixar = collect($japan['series'])->firstWhere('slug', 'pixar');
        $this->assertSame(3, $pixar['filmCount']);
        $this->assertSame(31_170_000_000, $pixar['totalYen']);
        $this->assertSame(['jp-tmdb-12', 'jp-tmdb-10193', 'jp-tmdb-585'], array_column($pixar['films'], 'id'));
        $this->assertSame('/series/brand/pixar/', $pixar['path']);
        $this->assertSame('ピクサー映画 興行収入ランキング（日本）', $pixar['heading']);

        $disney = collect($japan['series'])->firstWhere('slug', 'disney-animation');
        $this->assertSame(['jp-tmdb-109445', 'jp-tmdb-330457', 'jp-tmdb-269149'], array_column($disney['films'], 'id'));
        $this->assertNotContains('jp-tmdb-129', array_column($disney['films'], 'id'));

        $this->assertArrayNotHasKey('jp-tmdb-129', $japan['movieLinks']);
        $this->assertSame('/series/brand/pixar/', $japan['movieLinks']['jp-tmdb-10193']['href']);
        $this->assertSame('ピクサー', $japan['movieLinks']['jp-tmdb-10193']['name']);
    }

    public function test_mcu_includes_mcu_spider_man_and_excludes_sony(): void
    {
        $catalog = BrandCatalog::world($this->worldRankingFixture());
        $mcu = collect($catalog['series'])->firstWhere('slug', 'mcu');
        $this->assertIsArray($mcu);
        $ids = array_column($mcu['films'], 'id');
        $this->assertContains('tmdb-299534', $ids);
        $this->assertContains('tmdb-315635', $ids);
        $this->assertContains('tmdb-634649', $ids);
        $this->assertNotContains('tmdb-557', $ids);
        $this->assertNotContains('tmdb-335983', $ids);
        $this->assertNotContains('tmdb-569094', $ids);
        $this->assertSame('/series/global/brand/mcu/', $mcu['path']);
        $this->assertSame(3, $mcu['filmCount']);
        $this->assertSame(2942649778 + 880978185 + 1921426073, $mcu['totalUsd']);

        $this->assertNull(BrandCatalog::match([
            'tmdbId' => 557,
            'title' => 'スパイダーマン',
            'originalTitle' => 'Spider-Man',
            'boxOffice' => 821_708_551,
            'inRanking' => true,
        ]));
        $this->assertSame('ghibli', BrandCatalog::match([
            'tmdbId' => 129,
            'title' => '千と千尋の神隠し',
            'boxOffice' => 31_680_000_000,
            'inRanking' => true,
        ])['slug']);
        $this->assertNotSame('disney-animation', BrandCatalog::match([
            'tmdbId' => 129,
            'title' => '千と千尋の神隠し',
            'boxOffice' => 31_680_000_000,
            'inRanking' => true,
        ])['slug'] ?? null);
        $this->assertNull(BrandCatalog::match([
            'tmdbId' => 420818,
            'title' => 'ライオン・キング',
            'originalTitle' => 'The Lion King',
            'boxOffice' => 1_662_020_819,
            'inRanking' => true,
        ]));
    }

    public function test_brands_below_the_minimum_film_count_are_omitted(): void
    {
        $catalog = BrandCatalog::japan([[
            'id' => 'jp-tmdb-299534',
            'tmdbId' => 299534,
            'title' => 'アベンジャーズ/エンドゲーム',
            'rank' => 150,
            'inRanking' => true,
            'boxOffice' => 6_130_000_000,
        ], [
            'id' => 'jp-tmdb-969681',
            'tmdbId' => 969681,
            'title' => 'スパイダーマン:ブランド・ニュー・デイ',
            'rank' => 162,
            'inRanking' => true,
            'boxOffice' => 5_850_000_000,
        ]]);

        $this->assertSame([], $catalog['series']);
        $this->assertSame([], $catalog['movieLinks']);
    }

    public function test_brand_pages_match_series_seo_and_list_item_layout(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $catalog = BrandCatalog::japan($this->japanRankingFixture());
        $other = BrandCatalog::world($this->worldRankingFixture());
        $pixar = $this->invoke('withBrandSibling', collect($catalog['series'])->firstWhere('slug', 'pixar'), $other);

        $html = $this->invoke('seriesDetailHtml', $pixar, $catalog);
        $this->assertStringContainsString('<title>ピクサー映画 興行収入ランキング（日本） | MUBIRAN</title>', $html);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/brand/pixar/"', $html);
        $this->assertStringContainsString('3作品、合計311.7億円', $html);
        $this->assertStringContainsString('<div><dt>作品数</dt><dd>3</dd></div>', $html);
        $this->assertStringContainsString('<div><dt>合計</dt><dd>311.7億円</dd></div>', $html);
        $this->assertStringContainsString('list-item', $html);
        $this->assertStringContainsString('data-movie-id="jp-tmdb-10193"', $html);
        $this->assertStringContainsString('ItemList', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
        $this->assertStringContainsString('href="/series/"', $html);
        $this->assertStringContainsString('href="/series/brand/"', $html);
        $this->assertStringContainsString('ブランド一覧に戻る', $html);
        $this->assertStringNotContainsString('top-card', $html);
        $this->assertStringNotContainsString('作を除く', $html);

        $index = $this->invoke('brandIndexHtml', $catalog, $other);
        $this->assertStringContainsString('<title>日本の映画スタジオ・ユニバース興行収入 | MUBIRAN</title>', $index);
        $this->assertStringContainsString('href="/series/brand/pixar/"', $index);
        $this->assertStringContainsString('ピクサー', $index);
        $this->assertStringContainsString('シリーズ一覧', $index);

        $seriesIndex = $this->invoke('seriesIndexHtml', [
            'region' => 'japan',
            'indexPath' => '/series/',
            'series' => [[
                'slug' => 'トイ・ストーリー',
                'name' => 'トイ・ストーリー',
                'path' => '/series/トイ・ストーリー/',
                'filmCount' => 3,
                'totalYen' => 33_930_000_000,
                'films' => [['title' => 'トイ・ストーリー3', 'boxOffice' => 1]],
            ]],
        ], [
            'region' => 'global',
            'indexPath' => '/series/global/',
            'series' => [],
        ], $catalog);
        $this->assertStringContainsString('href="/series/brand/pixar/"', $seriesIndex);
        $this->assertStringContainsString('スタジオ・ユニバース別', $seriesIndex);
    }

    public function test_brand_pages_are_exported_and_listed_in_the_sitemap(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = sys_get_temp_dir().'/mubiran-brand-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $catalog = BrandCatalog::japan($this->japanRankingFixture());
        $other = ['region' => 'global', 'indexPath' => '/series/global/brand/', 'kind' => 'brand', 'series' => []];

        try {
            $this->invoke('exportBrandPages', $dir, $catalog, $other);
            $entries = $this->invoke('seriesSitemapEntries', $catalog);
            $index = (string) file_get_contents($dir.'/series/brand/index.html');
            $detail = is_file($dir.'/series/brand/pixar/index.html');
            $json = is_file($dir.'/data/series/japan/brand/pixar.json');
            $ghibli = is_file($dir.'/series/brand/ghibli/index.html');
        } finally {
            $this->removeDirectory($dir);
        }

        $this->assertTrue($detail);
        $this->assertTrue($json);
        $this->assertFalse($ghibli);
        $this->assertStringContainsString('href="/series/brand/pixar/"', $index);
        $locs = array_column($entries, 'loc');
        $this->assertTrue(collect($locs)->contains(fn (string $loc): bool => str_contains($loc, '/series/brand/')));
        $this->assertTrue(collect($locs)->contains(fn (string $loc): bool => str_contains($loc, '/series/brand/pixar/')));
        $this->assertFalse(collect($locs)->contains(fn (string $loc): bool => str_contains($loc, '/series/brand/ghibli/')));
    }

    public function test_movie_pages_link_to_the_brand_when_assigned(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $links = new ReflectionProperty(ExportStaticSite::class, 'brandMovieLinks');
        $links->setAccessible(true);
        $links->setValue($command, [
            'jp-tmdb-10193' => ['href' => '/series/brand/pixar/', 'name' => 'ピクサー'],
        ]);
        $method = new ReflectionMethod(ExportStaticSite::class, 'moviePageHtml');
        $method->setAccessible(true);
        $html = $method->invoke($command, [
            'id' => 'jp-tmdb-10193',
            'title' => 'トイ・ストーリー3',
            'revenue' => '108.0億円',
            'boxOffice' => 10_800_000_000,
            'rank' => 49,
            'inRanking' => true,
            'posterUrl' => '',
            'genres' => [],
        ], true, sys_get_temp_dir(), []);

        $this->assertStringContainsString('ブランド: <a href="/series/brand/pixar/">ピクサー</a>', $html);
        $this->assertStringContainsString('ブランドページ', $html);
    }

    public function test_non_ranking_and_unknown_films_are_not_added_to_brands(): void
    {
        $catalog = BrandCatalog::world([[
            'id' => 'tmdb-12',
            'tmdbId' => 12,
            'title' => 'ファインディング・ニモ',
            'rank' => null,
            'inRanking' => false,
            'boxOffice' => 940_335_536,
            'collectionId' => 137696,
        ], [
            'id' => 'tmdb-10193',
            'tmdbId' => 10193,
            'title' => 'トイ・ストーリー3',
            'rank' => 47,
            'inRanking' => true,
            'boxOffice' => 0,
        ]]);

        $this->assertSame([], $catalog['series']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function japanRankingFixture(): array
    {
        return [
            $this->movie('jp-tmdb-109445', 109445, 'アナと雪の女王', 25_500_000_000, 5),
            $this->movie('jp-tmdb-330457', 330457, 'アナと雪の女王2', 13_370_000_000, 33),
            $this->movie('jp-tmdb-10193', 10193, 'トイ・ストーリー3', 10_800_000_000, 49),
            $this->movie('jp-tmdb-585', 585, 'モンスターズ・インク', 9_370_000_000, 66),
            $this->movie('jp-tmdb-12', 12, 'ファインディング・ニモ', 11_000_000_000, 45),
            $this->movie('jp-tmdb-269149', 269149, 'ズートピア', 7_630_000_000, 105),
            $this->movie('jp-tmdb-129', 129, '千と千尋の神隠し', 31_680_000_000, 3),
            $this->movie('jp-tmdb-557', 557, 'スパイダーマン', 7_500_000_000, 107),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function worldRankingFixture(): array
    {
        return [
            $this->movie('tmdb-299534', 299534, 'アベンジャーズ／エンドゲーム', 2_942_649_778, 1, 'Avengers: Endgame'),
            $this->movie('tmdb-315635', 315635, 'スパイダーマン：ホームカミング', 880_978_185, 88, 'Spider-Man: Homecoming'),
            $this->movie('tmdb-634649', 634649, 'スパイダーマン：ノー・ウェイ・ホーム', 1_921_426_073, 9, 'Spider-Man: No Way Home'),
            $this->movie('tmdb-557', 557, 'スパイダーマン', 821_708_551, 111, 'Spider-Man'),
            $this->movie('tmdb-335983', 335983, 'ヴェノム', 856_085_151, 101, 'Venom'),
            $this->movie('tmdb-569094', 569094, 'スパイダーマン：アクロス・ザ・スパイダーバース', 690_824_738, 165, 'Spider-Man: Across the Spider-Verse'),
            $this->movie('tmdb-10193', 10193, 'トイ・ストーリー3', 1_067_316_101, 47, 'Toy Story 3'),
            $this->movie('tmdb-12', 12, 'ファインディング・ニモ', 940_335_536, 77, 'Finding Nemo'),
            $this->movie('tmdb-585', 585, 'モンスターズ・インク', 650_000_000, 184, 'Monsters, Inc.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movie(string $id, int $tmdbId, string $title, int $boxOffice, int $rank, ?string $original = null): array
    {
        return [
            'id' => $id,
            'slug' => $id,
            'tmdbId' => $tmdbId,
            'title' => $title,
            'originalTitle' => $original ?? $title,
            'rank' => $rank,
            'inRanking' => true,
            'boxOffice' => $boxOffice,
            'revenue' => 'x',
            'posterUrl' => '',
            'genres' => [],
        ];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ExportStaticSite::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->app->make(ExportStaticSite::class), ...$arguments);
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
