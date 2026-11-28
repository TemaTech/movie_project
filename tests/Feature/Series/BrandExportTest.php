<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Models\GlobalMovie;
use App\Models\JapaneseMovie;
use App\Services\Series\BrandCatalog;
use Illuminate\Support\Facades\Schema;
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
        $this->assertStringContainsString('<title>ピクサー映画 歴代興行収入ランキング（全3作・合計311.7億円）| MUBIRAN</title>', $html);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/brand/pixar/"', $html);
        $this->assertStringContainsString('1位『ファインディング・ニモ』110.0億円', $html);
        $this->assertStringContainsString('<h1 class="page-title" id="page-title">ピクサー映画 興行収入ランキング（日本）</h1>', $html);
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
        $this->assertStringContainsString('<title>映画スタジオ・ユニバース 興行収入ランキング（日本）| MUBIRAN</title>', $index);
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
        $this->assertStringContainsString('<title>映画シリーズ 興行収入ランキング（日本）| MUBIRAN</title>', $seriesIndex);
    }

    public function test_mcu_and_ghibli_brand_titles_do_not_repeat_eiga(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $world = BrandCatalog::world($this->worldRankingFixture());
        $mcu = collect($world['series'])->firstWhere('slug', 'mcu');
        $this->assertIsArray($mcu);
        $mcuHtml = $this->invoke('seriesDetailHtml', $mcu, $world);
        $this->assertStringContainsString(
            '<title>MCU映画 歴代興行収入ランキング（全3作・合計57.45億ドル）| MUBIRAN</title>',
            $mcuHtml
        );
        $this->assertStringContainsString('1位『アベンジャーズ／エンドゲーム』29.43億ドル', $mcuHtml);
        $this->assertStringContainsString('<h1 class="page-title" id="page-title">MCU映画 興行収入ランキング（世界）</h1>', $mcuHtml);
        $this->assertStringNotContainsString('MCU映画映画', $mcuHtml);

        $ghibli = [
            'slug' => 'ghibli',
            'name' => 'スタジオジブリ',
            'titleName' => 'スタジオジブリ映画',
            'heading' => 'スタジオジブリ映画 興行収入ランキング（日本）',
            'path' => '/series/brand/ghibli/',
            'kind' => 'brand',
            'filmCount' => 3,
            'totalYen' => 50_000_000_000,
            'indexable' => true,
            'films' => [[
                'id' => 'jp-tmdb-129',
                'title' => '千と千尋の神隠し',
                'boxOffice' => 31_680_000_000,
                'inRanking' => true,
                'rank' => 3,
            ], [
                'id' => 'jp-tmdb-8392',
                'title' => 'となりのトトロ',
                'boxOffice' => 10_000_000_000,
                'inRanking' => true,
                'rank' => 50,
            ], [
                'id' => 'jp-tmdb-4935',
                'title' => 'ハウルの動く城',
                'boxOffice' => 8_320_000_000,
                'inRanking' => true,
                'rank' => 80,
            ]],
        ];
        $ghibliHtml = $this->invoke('seriesDetailHtml', $ghibli, [
            'region' => 'japan',
            'indexPath' => '/series/brand/',
        ]);
        $this->assertStringContainsString(
            '<title>スタジオジブリ映画 歴代興行収入ランキング（全3作・合計500.0億円）| MUBIRAN</title>',
            $ghibliHtml
        );
        $this->assertStringContainsString('1位『千と千尋の神隠し』316.8億円', $ghibliHtml);
        $this->assertStringContainsString('<h1 class="page-title" id="page-title">スタジオジブリ映画 興行収入ランキング（日本）</h1>', $ghibliHtml);
        $this->assertStringNotContainsString('スタジオジブリ映画映画', $ghibliHtml);

        $worldIndex = $this->invoke('brandIndexHtml', $world, BrandCatalog::japan($this->japanRankingFixture()));
        $this->assertStringContainsString('<title>映画スタジオ・ユニバース 興行収入ランキング（世界）| MUBIRAN</title>', $worldIndex);
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

    public function test_mcu_lists_every_released_film_including_non_ranking_and_unknown(): void
    {
        $catalog = BrandCatalog::world($this->worldRankingFixture(), $this->worldExtrasFixture());
        $mcu = collect($catalog['series'])->firstWhere('slug', 'mcu');
        $this->assertIsArray($mcu);
        $this->assertTrue($mcu['allFilms']);
        $this->assertSame(5, $mcu['filmCount']);
        $this->assertSame(4, $mcu['knownCount']);
        $this->assertSame(1, $mcu['excludedUnknownCount']);
        $this->assertSame(2_942_649_778 + 1_921_426_073 + 880_978_185 + 585_174_222, $mcu['totalUsd']);
        $this->assertSame(
            ['tmdb-299534', 'tmdb-634649', 'tmdb-315635', 'tmdb-1726', 'tmdb-524434'],
            array_column($mcu['films'], 'id')
        );

        $ironMan = collect($mcu['films'])->firstWhere('id', 'tmdb-1726');
        $this->assertNull($ironMan['rank']);
        $this->assertFalse($ironMan['inRanking']);
        $eternals = collect($mcu['films'])->firstWhere('id', 'tmdb-524434');
        $this->assertSame(0, $eternals['boxOffice']);
        $this->assertSame('不明', $eternals['revenue']);
        $this->assertNull($eternals['revenueYen']);

        $ids = array_column($mcu['films'], 'id');
        $this->assertNotContains('tmdb-617126', $ids, '公開前の作品は載せない');
        $this->assertNotContains('tmdb-580489', $ids, 'ソニーの作品は載せない');
        $this->assertSame('/series/global/brand/mcu/', $catalog['movieLinks']['tmdb-1726']['href']);

        $pixar = collect($catalog['series'])->firstWhere('slug', 'pixar');
        $this->assertFalse($pixar['allFilms']);
        $this->assertNotContains('tmdb-2062', array_column($pixar['films'], 'id'), '全作品指定のないブランドはランキング外を足さない');
        $this->assertSame(0, $pixar['excludedUnknownCount']);
    }

    public function test_all_films_brand_page_counts_every_film_and_marks_unknown_revenue(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $world = BrandCatalog::world($this->worldRankingFixture(), $this->worldExtrasFixture());
        $mcu = collect($world['series'])->firstWhere('slug', 'mcu');
        $movies = $this->invoke('seriesMoviesForUi', $mcu, false);
        $this->assertCount(5, $movies);
        $this->assertSame([1, 2, 3, 4, null], array_column($movies, 'rank'));
        $this->assertSame('不明', $movies[4]['revenue']);
        $this->assertNull($movies[3]['rankingPosition']);

        $html = $this->invoke('seriesDetailHtml', $mcu, $world);
        $this->assertStringContainsString(
            '<title>MCU映画 歴代興行収入ランキング（全5作・合計63.30億ドル）| MUBIRAN</title>',
            $html
        );
        $this->assertStringContainsString('<h1 class="page-title" id="page-title">MCU映画 興行収入ランキング（世界）</h1>', $html);
        $this->assertStringContainsString('rel="canonical" href="https://mubiran.com/series/global/brand/mcu/"', $html);
        $this->assertStringContainsString('<div><dt>作品数</dt><dd>5</dd></div>', $html);
        $this->assertStringContainsString('<div><dt>合計</dt><dd>63.30億ドル（不明1作を除く）</dd></div>', $html);
        $this->assertStringContainsString('data-movie-id="tmdb-524434"', $html);
        $this->assertStringContainsString('data-movie-id="tmdb-1726"', $html);
        $this->assertStringContainsString('<span class="revenue-main">不明</span>', $html);
        $this->assertStringContainsString('MCUの劇場公開作品をすべて載せています', $html);
        $this->assertStringContainsString('5作品、合計63.30億ドル（不明1作を除く）', $html);

        $index = $this->invoke('brandIndexHtml', $world, BrandCatalog::japan($this->japanRankingFixture()));
        $this->assertStringContainsString('MCUはランキング外も含めて劇場公開作品をすべて載せています。', $index);
        $this->assertStringContainsString('<td class="series-num">5</td>', $index);
    }

    public function test_mcu_brand_map_lists_every_theatrical_film_for_the_world_page(): void
    {
        $mcu = collect(BrandCatalog::definitions())->firstWhere('slug', 'mcu');
        $this->assertIsArray($mcu);
        $this->assertTrue(BrandCatalog::listsAllFilms($mcu, 'global'));
        $this->assertFalse(BrandCatalog::listsAllFilms($mcu, 'japan'));

        $ids = array_map('intval', $mcu['tmdbIds']);
        $this->assertCount(38, array_unique($ids));
        foreach ([1726, 1724, 497698, 566525, 524434, 640146, 609681, 822119, 986056, 617126, 969681] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertNotContains(986669, $ids);
        $this->assertSame([], array_intersect($ids, array_map('intval', $mcu['excludeTmdbIds'])));

        $targets = BrandCatalog::allFilmsTmdbIds('global');
        $this->assertSame('mcu', $targets[617126] ?? null);
        $this->assertSame([], BrandCatalog::allFilmsTmdbIds('japan'));
        foreach (BrandCatalog::definitions() as $brand) {
            if (($brand['slug'] ?? '') !== 'mcu') {
                $this->assertFalse(BrandCatalog::listsAllFilms($brand, 'global'), (string) $brand['slug']);
            }
        }
    }

    public function test_ranking_movies_with_collection_ids_match_at_most_one_brand(): void
    {
        $movies = $this->productionRankingMoviesWithCollectionIds();
        if ($movies === null) {
            $this->assertBrandMapIdsAreUnique();

            return;
        }

        $this->assertNotEmpty($movies);
        foreach ($movies as $movie) {
            $hits = BrandCatalog::matchingBrands($movie);
            $this->assertLessThanOrEqual(
                1,
                count($hits),
                sprintf(
                    '%s (tmdb %s, collection %s) matched %s',
                    (string) ($movie['title'] ?? $movie['id'] ?? '?'),
                    (string) ($movie['tmdbId'] ?? ''),
                    (string) ($movie['collectionId'] ?? ''),
                    implode(', ', array_column($hits, 'slug'))
                )
            );
        }
    }

    public function test_kung_fu_panda_collection_matches_only_dreamworks(): void
    {
        $pixarCollections = [];
        $dreamworksCollections = [];
        foreach (BrandCatalog::definitions() as $brand) {
            $slug = (string) ($brand['slug'] ?? '');
            $ids = array_map('intval', $brand['collectionIds'] ?? []);
            if ($slug === 'pixar') {
                $pixarCollections = $ids;
            }
            if ($slug === 'dreamworks') {
                $dreamworksCollections = $ids;
            }
        }
        $this->assertNotContains(77816, $pixarCollections);
        $this->assertContains(77816, $dreamworksCollections);

        foreach ([
            ['tmdbId' => 9502, 'title' => 'カンフー・パンダ', 'originalTitle' => 'Kung Fu Panda', 'boxOffice' => 632_091_832, 'rank' => 191],
            ['tmdbId' => 49444, 'title' => 'カンフー・パンダ２', 'originalTitle' => 'Kung Fu Panda 2', 'boxOffice' => 665_692_281, 'rank' => 175],
        ] as $film) {
            $movie = $film + [
                'id' => 'tmdb-'.$film['tmdbId'],
                'collectionId' => 77816,
                'inRanking' => true,
            ];
            $hits = BrandCatalog::matchingBrands($movie);
            $this->assertSame(
                ['dreamworks'],
                array_column($hits, 'slug'),
                $film['title'].' with collection 77816 should match only DreamWorks'
            );
            $this->assertSame('dreamworks', BrandCatalog::match($movie)['slug'] ?? null);
        }
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
     * 歴代ランキング外（global_movies.in_ranking = false）の作品。
     *
     * @return list<array<string, mixed>>
     */
    private function worldExtrasFixture(): array
    {
        return [
            $this->extra('tmdb-1726', 1726, 'アイアンマン', 585_174_222, '2008-04-30', 'Iron Man'),
            $this->extra('tmdb-524434', 524434, 'エターナルズ', 0, '2021-11-03', 'Eternals'),
            $this->extra('tmdb-617126', 617126, 'ファンタスティック4：ファースト・ステップ', 0, '2099-07-25', 'The Fantastic Four: First Steps'),
            $this->extra('tmdb-580489', 580489, 'ヴェノム：レット・ゼア・ビー・カーネイジ', 506_863_592, '2021-09-30', 'Venom: Let There Be Carnage'),
            $this->extra('tmdb-2062', 2062, 'レミーのおいしいレストラン', 623_722_818, '2007-06-22', 'Ratatouille'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extra(string $id, int $tmdbId, string $title, int $boxOffice, string $releaseDate, string $original): array
    {
        return [
            'id' => $id,
            'slug' => $id,
            'tmdbId' => $tmdbId,
            'title' => $title,
            'originalTitle' => $original,
            'rank' => null,
            'inRanking' => false,
            'collectionId' => null,
            'releaseDate' => $releaseDate,
            'releaseYear' => (int) substr($releaseDate, 0, 4),
            'boxOffice' => $boxOffice,
            'revenue' => $boxOffice > 0 ? number_format($boxOffice / 100000000, 2).'億ドル' : '不明',
            'revenueYen' => $boxOffice > 0 ? '1.0億円' : '0.0億円',
            'posterUrl' => '',
            'genres' => [],
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

    /**
     * @return list<array<string, mixed>>|null
     */
    private function productionRankingMoviesWithCollectionIds(): ?array
    {
        try {
            $schema = Schema::connection('mysql');
            if (! $schema->hasTable('global_movies')) {
                return null;
            }
            $query = GlobalMovie::query()->orderBy('box_office', 'desc');
            if ($schema->hasColumn('global_movies', 'in_ranking')) {
                $query->where('in_ranking', true);
            }
            $global = $query->get();
            if ($global->isEmpty()) {
                return null;
            }

            $movies = [];
            foreach ($global as $index => $movie) {
                $movies[] = [
                    'id' => $movie->movie_id,
                    'tmdbId' => $movie->tmdb_id,
                    'title' => $movie->title,
                    'originalTitle' => $movie->original_title,
                    'collectionId' => $movie->tmdb_collection_id ?? null,
                    'boxOffice' => (int) $movie->box_office,
                    'rank' => $index + 1,
                    'inRanking' => true,
                ];
            }
            if ($schema->hasTable('japanese_movies')) {
                $japanHasCollection = $schema->hasColumn('japanese_movies', 'tmdb_collection_id');
                foreach (JapaneseMovie::orderBy('box_office', 'desc')->get() as $index => $movie) {
                    $movies[] = [
                        'id' => $movie->movie_id,
                        'tmdbId' => $movie->tmdb_id,
                        'title' => $movie->title,
                        'originalTitle' => $movie->original_title,
                        'collectionId' => $japanHasCollection ? ($movie->tmdb_collection_id ?? null) : null,
                        'boxOffice' => (int) $movie->box_office,
                        'rank' => $index + 1,
                        'inRanking' => true,
                    ];
                }
            }

            return $movies;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertBrandMapIdsAreUnique(): void
    {
        $tmdbOwners = [];
        $collectionOwners = [];
        foreach (BrandCatalog::definitions() as $brand) {
            $slug = (string) ($brand['slug'] ?? '');
            foreach ($brand['tmdbIds'] ?? [] as $id) {
                if (! is_numeric($id) || (int) $id <= 0) {
                    continue;
                }
                $id = (int) $id;
                if (isset($tmdbOwners[$id])) {
                    $this->fail("tmdbId {$id} is registered on {$tmdbOwners[$id]} and {$slug}");
                }
                $tmdbOwners[$id] = $slug;
            }
            foreach ($brand['collectionIds'] ?? [] as $id) {
                if (! is_numeric($id) || (int) $id <= 0) {
                    continue;
                }
                $id = (int) $id;
                if (isset($collectionOwners[$id])) {
                    $this->fail("collectionId {$id} is registered on {$collectionOwners[$id]} and {$slug}");
                }
                $collectionOwners[$id] = $slug;
            }
        }
        $this->assertNotEmpty($tmdbOwners);
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
