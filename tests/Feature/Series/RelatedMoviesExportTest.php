<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Services\Series\RelatedMovies;
use App\Services\Series\SeriesCatalog;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class RelatedMoviesExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureViteManifest();
    }

    public function test_endgame_world_page_lists_series_then_brand_neighbors(): void
    {
        $endgame = $this->film('tmdb-299534', 'アベンジャーズ／エンドゲーム', '2019-04-24', 2_942_649_778, 1, '29.43億ドル');
        $indexes = RelatedMovies::buildIndexes(
            [[
                'films' => [
                    $this->film('tmdb-24428', 'アベンジャーズ', '2012-04-25', 1_518_815_515, 15, '15.19億ドル'),
                    $this->film('tmdb-99861', 'アベンジャーズ／エイジ・オブ・ウルトロン', '2015-04-22', 1_405_413_868, 20, '14.05億ドル'),
                    $this->film('tmdb-299536', 'アベンジャーズ／インフィニティ・ウォー', '2018-04-25', 2_052_415_039, 8, '20.52億ドル'),
                    $endgame,
                ],
            ]],
            [[
                'films' => [
                    $endgame,
                    $this->film('tmdb-634649', 'スパイダーマン：ノー・ウェイ・ホーム', '2021-12-15', 1_921_426_073, 9, '19.21億ドル'),
                    $this->film('tmdb-315635', 'スパイダーマン：ホームカミング', '2017-07-05', 880_978_185, 88, '8.81億ドル'),
                ],
            ]],
            [
                $endgame,
                $this->film('tmdb-19995', 'アバター', '2009-12-16', 2_923_706_026, 2, '29.24億ドル'),
                $this->film('tmdb-76600', 'アバター：ウェイ・オブ・ウォーター', '2022-12-14', 2_320_250_281, 3, '23.20億ドル'),
            ],
            [],
            [],
        );

        $picked = RelatedMovies::pick($endgame, [
            'series' => $indexes['series'],
            'brand' => $indexes['brand'],
            'ranking' => $indexes['rankingGlobal'],
        ]);
        $this->assertCount(5, $picked);
        $this->assertSame('アベンジャーズ／インフィニティ・ウォー', $picked[0]['title']);
        $this->assertSame('/movies/tmdb-299536/', $picked[0]['href']);
        $this->assertSame('アベンジャーズ／エイジ・オブ・ウルトロン', $picked[1]['title']);
        $this->assertContains('スパイダーマン：ノー・ウェイ・ホーム', array_column($picked, 'title'));
        $this->assertNotContains('アベンジャーズ／エンドゲーム', array_column($picked, 'title'));

        $html = $this->moviePageHtml($endgame, false, $indexes);
        $this->assertStringContainsString('<section class="movie-section movie-related">', $html);
        $this->assertStringContainsString('<h2>関連作品</h2>', $html);
        $this->assertStringContainsString('href="/movies/tmdb-299536/"', $html);
        $this->assertStringContainsString('アベンジャーズ／インフィニティ・ウォー', $html);
        $this->assertStringContainsString('20.52億ドル', $html);
        $this->assertStringContainsString('<h1 class="movie-page-title">アベンジャーズ／エンドゲームの興行収入</h1>', $html);
        $this->assertSame(1, substr_count($html, '<h1 class="movie-page-title">'));
    }

    public function test_conan_and_extra_pages_link_to_release_order_neighbors(): void
    {
        $catalog = SeriesCatalog::japan([]);
        $conan = collect($catalog['series'])->firstWhere('name', '名探偵コナン');
        $this->assertIsArray($conan);

        $films = array_values(array_filter(
            $conan['films'],
            fn (array $film): bool => ($film['kind'] ?? '') === 'theatrical' && ($film['id'] ?? '') !== ''
        ));
        $this->assertGreaterThanOrEqual(5, count($films));

        $exportable = [];
        foreach ($films as $film) {
            $exportable[(string) $film['id']] = true;
        }
        $indexes = RelatedMovies::buildIndexes([$conan], [], [], [], $exportable);

        $first = $films[0];
        foreach ($films as $film) {
            if ((int) ($film['releaseYear'] ?? 0) === 1997 && str_contains((string) $film['title'], '時計じかけの摩天楼')) {
                $first = $film;
                break;
            }
        }
        $later = null;
        foreach ($films as $film) {
            if ((int) ($film['releaseYear'] ?? 0) === 2024) {
                $later = $film;
                break;
            }
        }
        $this->assertNotNull($later, 'Conan 2024 film should exist in the snapshot');

        $firstRelated = RelatedMovies::pick($first, [
            'series' => $indexes['series'],
            'brand' => $indexes['brand'],
            'ranking' => [],
        ]);
        $this->assertCount(5, $firstRelated);
        $this->assertStringContainsString('14番目の標的', $firstRelated[0]['title']);
        $this->assertFalse((bool) ($first['inRanking'] ?? false));
        $this->assertStringStartsWith('/movies/', $firstRelated[0]['href']);

        $laterRelated = RelatedMovies::pick($later, [
            'series' => $indexes['series'],
            'brand' => $indexes['brand'],
            'ranking' => [],
        ]);
        $this->assertCount(5, $laterRelated);
        $this->assertNotContains($later['id'], array_column($laterRelated, 'id'));

        $extraHtml = $this->moviePageHtml([
            'id' => $first['id'],
            'title' => $first['title'],
            'revenue' => $first['revenue'] ?? '11.0億円',
            'boxOffice' => $first['boxOffice'] ?? 1_100_000_000,
            'rank' => null,
            'inRanking' => false,
            'seriesName' => '名探偵コナン',
            'posterUrl' => '',
            'genres' => [],
        ], true, $indexes);
        $this->assertStringContainsString('<h2>関連作品</h2>', $extraHtml);
        $this->assertStringContainsString('14番目の標的', $extraHtml);
        $this->assertStringContainsString('<dt>日本ランキング</dt><dd>圏外</dd>', $extraHtml);
        $this->assertStringNotContainsString('日本ランキング圏外', $extraHtml);
        $this->assertStringContainsString('<h1 class="movie-page-title">'.$first['title'].'の興行収入</h1>', $extraHtml);
    }

    public function test_world_extra_uses_series_neighbors(): void
    {
        $phoenix = $this->film('tmdb-675', 'ハリー・ポッターと不死鳥の騎士団', '2007-07-11', 938_212_738, null, '9.38億ドル');
        $indexes = RelatedMovies::buildIndexes(
            [[
                'films' => [
                    $this->film('tmdb-671', 'ハリー・ポッターと賢者の石', '2001-11-16', 1_029_374_615, 56, '10.29億ドル'),
                    $this->film('tmdb-672', 'ハリー・ポッターと秘密の部屋', '2002-11-15', 876_688_482, 89, '8.77億ドル'),
                    $phoenix,
                    $this->film('tmdb-12445', 'ハリー・ポッターと死の秘宝 PART2', '2011-07-07', 1_342_139_727, 23, '13.42億ドル'),
                ],
            ]],
            [],
            [],
            [],
            [],
        );

        $picked = RelatedMovies::pick($phoenix, [
            'series' => $indexes['series'],
            'brand' => $indexes['brand'],
            'ranking' => [],
        ]);
        $this->assertCount(3, $picked);
        $this->assertSame('ハリー・ポッターと秘密の部屋', $picked[0]['title']);
        $this->assertSame('ハリー・ポッターと死の秘宝 PART2', $picked[1]['title']);

        $html = $this->moviePageHtml([
            'id' => 'tmdb-675',
            'title' => 'ハリー・ポッターと不死鳥の騎士団',
            'revenue' => '9.38億ドル',
            'boxOffice' => 938_212_738,
            'rank' => null,
            'inRanking' => false,
            'collectionName' => 'ハリー・ポッター コレクション',
            'posterUrl' => '',
            'genres' => [],
        ], false, $indexes);
        $this->assertStringContainsString('<h2>関連作品</h2>', $html);
        $this->assertStringContainsString('href="/movies/tmdb-672/"', $html);
        $this->assertStringNotContainsString('世界ランキング圏外', $html);
        $this->assertStringContainsString('<dt>世界ランキング</dt><dd>圏外</dd>', $html);
    }

    public function test_movie_page_omits_related_section_when_empty(): void
    {
        $html = $this->moviePageHtml([
            'id' => 'tmdb-1',
            'title' => '単独作',
            'revenue' => '1.00億ドル',
            'rank' => 1,
            'posterUrl' => '',
            'genres' => [],
        ], false, [
            'series' => [],
            'brand' => [],
            'rankingGlobal' => [],
            'rankingJapan' => [],
        ]);

        $this->assertStringNotContainsString('関連作品', $html);
        $this->assertStringContainsString('<h1 class="movie-page-title">単独作の興行収入</h1>', $html);
    }

    public function test_japan_series_pages_with_fewer_than_three_related_are_the_short_series(): void
    {
        $catalog = SeriesCatalog::japan([]);
        $exportable = [];
        foreach ($catalog['series'] as $series) {
            foreach ($series['films'] as $film) {
                $id = (string) ($film['id'] ?? '');
                $title = trim((string) ($film['title'] ?? ''));
                if ($id === '' || $title === '') {
                    continue;
                }
                $exportable[$id] = true;
            }
        }

        $ids = array_keys($exportable);
        $built = RelatedMovies::buildIndexes($catalog['series'], [], [], [], $exportable);
        $expectedShort = 0;
        foreach ($ids as $id) {
            if (count($built['series'][$id] ?? []) < 4) {
                $expectedShort++;
            }
        }
        $count = RelatedMovies::countFewerThan($ids, [
            'series' => $built['series'],
            'brand' => $built['brand'],
            'ranking' => $built['rankingJapan'],
        ]);

        $this->assertSame($expectedShort, $count);
        $this->assertLessThan(count($ids), $count);
        fwrite(STDERR, "\nRelated movies: {$count} of ".count($ids)." japan series pages have fewer than 3 links\n");
    }

    /**
     * @param  array<string, mixed>  $movie
     * @param  array{
     *     series?: array<string, list<array<string, mixed>>>,
     *     brand?: array<string, list<array<string, mixed>>>,
     *     rankingGlobal?: list<array<string, mixed>>,
     *     rankingJapan?: list<array<string, mixed>>
     * }  $indexes
     */
    private function moviePageHtml(array $movie, bool $isJapan, array $indexes): string
    {
        $movie['posterUrl'] = $movie['posterUrl'] ?? '';
        $movie['genres'] = $movie['genres'] ?? [];
        $command = $this->app->make(ExportStaticSite::class);
        $property = new ReflectionProperty(ExportStaticSite::class, 'relatedIndexes');
        $property->setAccessible(true);
        $property->setValue($command, [
            'series' => $indexes['series'] ?? [],
            'brand' => $indexes['brand'] ?? [],
            'rankingGlobal' => $indexes['rankingGlobal'] ?? [],
            'rankingJapan' => $indexes['rankingJapan'] ?? [],
        ]);
        $method = new ReflectionMethod(ExportStaticSite::class, 'moviePageHtml');
        $method->setAccessible(true);

        return $method->invoke($command, $movie, $isJapan, sys_get_temp_dir(), []);
    }

    /**
     * @return array<string, mixed>
     */
    private function film(
        string $id,
        string $title,
        string $releaseDate,
        int $boxOffice,
        ?int $rank,
        string $revenue,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'href' => '/movies/'.$id.'/',
            'releaseDate' => $releaseDate,
            'releaseYear' => (int) substr($releaseDate, 0, 4),
            'boxOffice' => $boxOffice,
            'rank' => $rank,
            'inRanking' => $rank !== null,
            'revenue' => $revenue,
        ];
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
