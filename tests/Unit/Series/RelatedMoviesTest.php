<?php

namespace Tests\Unit\Series;

use App\Services\Series\RelatedMovies;
use Tests\TestCase;

class RelatedMoviesTest extends TestCase
{
    public function test_series_neighbors_come_before_brand_and_ranking(): void
    {
        $movie = $this->film('tmdb-3', 'Three', '2019-01-01', 30, 3, '3.00億ドル');
        $picked = RelatedMovies::pick($movie, [
            'series' => [
                'tmdb-3' => [
                    $this->film('tmdb-1', 'One', '2017-01-01', 10, 10, '1.00億ドル'),
                    $this->film('tmdb-2', 'Two', '2018-01-01', 20, 9, '2.00億ドル'),
                    $movie,
                    $this->film('tmdb-4', 'Four', '2020-01-01', 40, 8, '4.00億ドル'),
                ],
            ],
            'brand' => [
                'tmdb-3' => [
                    $this->film('tmdb-99', 'Brand Hit', '2010-01-01', 90, 2, '9.00億ドル'),
                    $movie,
                ],
            ],
            'ranking' => [
                $this->film('tmdb-50', 'Rank Neighbor', '2011-01-01', 50, 2, '5.00億ドル'),
                $movie,
                $this->film('tmdb-51', 'Rank Other', '2012-01-01', 51, 4, '5.10億ドル'),
            ],
        ]);

        $this->assertSame(
            ['tmdb-2', 'tmdb-4', 'tmdb-1', 'tmdb-99', 'tmdb-50'],
            array_column($picked, 'id')
        );
        $this->assertCount(5, $picked);
        $this->assertNotContains('tmdb-3', array_column($picked, 'id'));
        $this->assertSame('Two', $picked[0]['title']);
        $this->assertSame('/movies/tmdb-2/', $picked[0]['href']);
        $this->assertSame('2.00億ドル', $picked[0]['revenue']);
    }

    public function test_duplicates_and_self_are_dropped_and_cap_is_five(): void
    {
        $movie = $this->film('tmdb-1', 'Lead', '2019-01-01', 100, 1, '10.00億ドル');
        $neighbor = $this->film('tmdb-2', 'Same', '2018-01-01', 90, 2, '9.00億ドル');
        $picked = RelatedMovies::pick($movie, [
            'series' => ['tmdb-1' => [$movie, $neighbor]],
            'brand' => ['tmdb-1' => [$movie, $neighbor, $this->film('tmdb-3', 'Brand', '2017-01-01', 80, 3, '8.00億ドル')]],
            'ranking' => [
                $movie,
                $neighbor,
                $this->film('tmdb-4', 'R4', '2016-01-01', 70, 4, '7.00億ドル'),
                $this->film('tmdb-5', 'R5', '2015-01-01', 60, 5, '6.00億ドル'),
                $this->film('tmdb-6', 'R6', '2014-01-01', 50, 6, '5.00億ドル'),
                $this->film('tmdb-7', 'R7', '2013-01-01', 40, 7, '4.00億ドル'),
            ],
        ]);

        $ids = array_column($picked, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertNotContains('tmdb-1', $ids);
        $this->assertCount(5, $picked);
        $this->assertSame('tmdb-2', $ids[0]);
        $this->assertSame('tmdb-3', $ids[1]);
    }

    public function test_ranking_neighbors_fill_when_series_and_brand_are_thin(): void
    {
        $movie = $this->film('tmdb-10', 'Solo', '2020-01-01', 10, 10, '1.00億ドル');
        $ranking = [];
        for ($rank = 8; $rank <= 13; $rank++) {
            $ranking[] = $this->film('tmdb-'.$rank, 'Rank '.$rank, '2010-01-01', 100 - $rank, $rank, $rank.'.00億ドル');
        }

        $picked = RelatedMovies::pick($movie, [
            'series' => [],
            'brand' => [],
            'ranking' => $ranking,
        ]);

        $this->assertSame(
            ['tmdb-9', 'tmdb-11', 'tmdb-8', 'tmdb-12', 'tmdb-13'],
            array_column($picked, 'id')
        );
    }

    public function test_out_of_ranking_films_skip_rank_neighbors(): void
    {
        $extra = $this->film('jp-series-abc', 'Extra', '1997-04-19', 1_100_000_000, null, '11.0億円');
        unset($extra['rank']);
        $picked = RelatedMovies::pick($extra, [
            'series' => [
                'jp-series-abc' => [
                    $extra,
                    $this->film('jp-series-def', 'Next', '1998-04-18', 1_850_000_000, null, '18.5億円'),
                ],
            ],
            'brand' => [],
            'ranking' => [
                $this->film('jp-tmdb-1', 'Ranked', '2024-01-01', 10_000_000_000, 1, '100.0億円'),
                $this->film('jp-tmdb-2', 'Ranked 2', '2023-01-01', 9_000_000_000, 2, '90.0億円'),
                $this->film('jp-tmdb-3', 'Ranked 3', '2022-01-01', 8_000_000_000, 3, '80.0億円'),
            ],
        ]);

        $this->assertSame(['jp-series-def'], array_column($picked, 'id'));
    }

    public function test_overlapping_series_keep_the_larger_membership(): void
    {
        $shared = $this->film('jp-series-dora', 'STAND BY ME ドラえもん', '2014-08-08', 8_380_000_000, null, '83.8億円');
        $indexes = RelatedMovies::buildIndexes(
            [[
                'films' => [
                    $this->film('jp-series-a', '映画ドラえもん のび太の宇宙小戦争', '2021-03-05', 3_340_000_000, null, '33.4億円'),
                    $this->film('jp-series-b', '映画ドラえもん のび太の地球交響楽', '2024-03-01', 5_360_000_000, null, '53.6億円'),
                    $this->film('jp-series-c', '映画ドラえもん のび太の絵世界物語', '2025-03-07', 4_330_000_000, null, '43.3億円'),
                    $shared,
                ],
            ], [
                'films' => [$shared],
            ]],
            [],
            [],
            [],
            [],
        );

        $picked = RelatedMovies::pick($shared, [
            'series' => $indexes['series'],
            'brand' => $indexes['brand'],
            'ranking' => [],
        ]);
        $this->assertCount(3, $picked);
        $this->assertSame('映画ドラえもん のび太の宇宙小戦争', $picked[0]['title']);
    }

    public function test_non_exportable_films_are_omitted_from_indexes(): void
    {
        $indexes = RelatedMovies::buildIndexes(
            [[
                'films' => [
                    $this->film('tmdb-1', 'Kept', '2018-01-01', 10, 1, '1.00億ドル'),
                    $this->film('tmdb-2', 'Dropped', '2019-01-01', 20, 2, '2.00億ドル'),
                ],
            ]],
            [],
            [$this->film('tmdb-1', 'Kept', '2018-01-01', 10, 1, '1.00億ドル')],
            [],
            ['tmdb-1' => true],
        );

        $this->assertArrayHasKey('tmdb-1', $indexes['series']);
        $this->assertArrayNotHasKey('tmdb-2', $indexes['series']);
        $this->assertSame(['tmdb-1'], array_column($indexes['series']['tmdb-1'], 'id'));
        $this->assertSame(['tmdb-1'], array_column($indexes['rankingGlobal'], 'id'));
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
            'revenue' => $revenue,
        ];
    }
}
