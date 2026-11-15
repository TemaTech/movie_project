<?php

namespace Tests\Unit\BoxOffice;

use App\Services\BoxOffice\GlobalRankingAssembler;
use PHPUnit\Framework\TestCase;

class GlobalRankingAssemblerTest extends TestCase
{
    public function test_finalize_keeps_highest_grossing_films_and_assigns_ranks(): void
    {
        $ranking = GlobalRankingAssembler::finalize([
            $this->row(1, 100),
            $this->row(2, 300),
            $this->row(3, 200),
            $this->row(2, 250),
        ], 2);

        $this->assertSame([2, 3], array_column($ranking, 'tmdb_id'));
        $this->assertSame([1, 2], array_column($ranking, 'rank'));
        $this->assertSame([300, 200], array_column($ranking, 'box_office'));
        $this->assertSame([1, 1], array_column($ranking, 'in_ranking'));
    }

    public function test_cutoff_is_the_lowest_film_that_still_makes_the_limit(): void
    {
        $movies = [
            $this->row(1, 500),
            $this->row(2, 400),
            $this->row(3, 300),
        ];

        $this->assertSame(400, GlobalRankingAssembler::cutoff($movies, 2));
        $this->assertSame(300, GlobalRankingAssembler::cutoff($movies, 200));
        $this->assertSame(0, GlobalRankingAssembler::cutoff([], 200));
    }

    public function test_discover_omission_is_filled_from_previous_or_extra_records(): void
    {
        $discover = [
            $this->row(10, 1_000_000_000),
            $this->row(11, 900_000_000),
            $this->row(12, 800_000_000),
        ];
        $missing = $this->row(99861, 1_405_403_694);

        $fromPrevious = GlobalRankingAssembler::finalize([...$discover, $missing], 3);
        $this->assertSame(99861, $fromPrevious[0]['tmdb_id']);
        $this->assertSame(1, $fromPrevious[0]['rank']);
        $this->assertSame([99861, 10, 11], array_column($fromPrevious, 'tmdb_id'));

        $fromExtra = GlobalRankingAssembler::finalize([...$discover, $missing], 3);
        $this->assertSame([99861, 10, 11], array_column($fromExtra, 'tmdb_id'));
        $this->assertNotContains(12, array_column($fromExtra, 'tmdb_id'));
    }

    public function test_production_dropouts_return_to_expected_ranks(): void
    {
        $path = dirname(__DIR__, 2).'/fixtures/global-ranking-build-393.json';
        $discover = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($discover);
        $this->assertCount(200, $discover);

        $movies = array_map(fn (array $row): array => [
            'tmdb_id' => $row['tmdbId'],
            'movie_id' => $row['id'],
            'title' => $row['title'],
            'box_office' => $row['boxOffice'],
        ], $discover);
        $movies[] = [
            'tmdb_id' => 99861,
            'movie_id' => 'tmdb-99861',
            'title' => 'アベンジャーズ／エイジ・オブ・ウルトロン',
            'box_office' => 1_405_403_694,
        ];
        $movies[] = [
            'tmdb_id' => 1858,
            'movie_id' => 'tmdb-1858',
            'title' => 'トランスフォーマー',
            'box_office' => 709_709_780,
        ];

        $ranking = GlobalRankingAssembler::finalize($movies, 200);
        $ids = array_column($ranking, 'tmdb_id');

        $this->assertCount(200, $ranking);
        $this->assertSame(99861, $ranking[20]['tmdb_id']);
        $this->assertSame(21, $ranking[20]['rank']);
        $this->assertSame(1858, $ranking[158]['tmdb_id']);
        $this->assertSame(159, $ranking[158]['rank']);
        $this->assertNotContains(1061474, $ids);
        $this->assertNotContains(330, $ids);
        $this->assertSame(263115, $ranking[199]['tmdb_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $tmdbId, int $boxOffice): array
    {
        return [
            'tmdb_id' => $tmdbId,
            'movie_id' => 'tmdb-'.$tmdbId,
            'title' => 'Movie '.$tmdbId,
            'box_office' => $boxOffice,
        ];
    }
}
