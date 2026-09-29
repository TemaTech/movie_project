<?php

namespace Tests\Unit\BoxOffice;

use App\Services\BoxOffice\Insights;
use App\Services\BoxOffice\Registry;
use App\Services\BoxOffice\TheatricalRun;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class TheatricalRunTest extends TestCase
{
    public function test_revival_theatrical_date_marks_an_old_movie_active(): void
    {
        $result = TheatricalRun::assess('2019-04-24', [
            [
                'iso_3166_1' => 'US',
                'release_dates' => [
                    ['type' => 3, 'release_date' => '2019-04-26T00:00:00.000Z'],
                    ['type' => 3, 'release_date' => '2026-09-25T00:00:00.000Z', 'note' => 'Avengers Endgame: Encore'],
                ],
            ],
            [
                'iso_3166_1' => 'FR',
                'release_dates' => [
                    ['type' => 3, 'release_date' => '2026-09-23T00:00:00.000Z'],
                    ['type' => 4, 'release_date' => '2026-09-28T00:00:00.000Z'],
                ],
            ],
        ], new DateTimeImmutable('2026-09-29T12:00:00+09:00'));

        $this->assertTrue($result['active']);
        $this->assertSame('2026-09-25', $result['revivalDate']);
    }

    public function test_recent_primary_release_stays_active_without_a_revival_date(): void
    {
        $result = TheatricalRun::assess('2026-08-01', [
            [
                'iso_3166_1' => 'US',
                'release_dates' => [
                    ['type' => 3, 'release_date' => '2026-08-01T00:00:00.000Z'],
                ],
            ],
        ], new DateTimeImmutable('2026-09-29T12:00:00+09:00'));

        $this->assertTrue($result['active']);
        $this->assertNull($result['revivalDate']);
    }

    public function test_old_movie_without_a_current_theatrical_date_stays_inactive(): void
    {
        $result = TheatricalRun::assess('2019-04-24', [
            [
                'iso_3166_1' => 'US',
                'release_dates' => [
                    ['type' => 3, 'release_date' => '2019-04-26T00:00:00.000Z'],
                    ['type' => 4, 'release_date' => '2026-09-01T00:00:00.000Z'],
                ],
            ],
        ], new DateTimeImmutable('2026-09-29T12:00:00+09:00'));

        $this->assertFalse($result['active']);
        $this->assertNull($result['revivalDate']);
    }

    public function test_registry_keeps_original_release_and_stores_revival_date(): void
    {
        $dir = sys_get_temp_dir().'/boxoffice-revival-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $registry = new Registry($dir.'/registry.json');

        $opened = $registry->resolve([
            'region' => 'global',
            'title' => 'アベンジャーズ／エンドゲーム',
            'tmdbId' => 299534,
            'releaseDate' => '2019-04-24',
            'releaseDatePrecision' => 'day',
            'currentReleaseDate' => '2026-09-25',
        ]);

        $this->assertSame('2019-04-24', $opened['releaseDate']);
        $this->assertSame(2019, $opened['releaseYear']);
        $this->assertSame('2026-09-25', $opened['currentReleaseDate']);

        $closed = $registry->resolve([
            'region' => 'global',
            'title' => 'アベンジャーズ／エンドゲーム',
            'tmdbId' => 299534,
            'releaseDate' => '2019-04-24',
            'releaseDatePrecision' => 'day',
            'currentReleaseDate' => null,
        ]);

        $this->assertSame('2019-04-24', $closed['releaseDate']);
        $this->assertArrayNotHasKey('currentReleaseDate', $closed);

        if (is_file($dir.'/registry.json')) {
            unlink($dir.'/registry.json');
        }
        rmdir($dir);
    }

    public function test_insights_count_days_from_the_revival_opening(): void
    {
        $now = new DateTimeImmutable('2026-09-29T12:00:00+09:00');
        $result = Insights::compute('global', [[
            'key' => 'tmdb-299534',
            'title' => 'アベンジャーズ／エンドゲーム',
            'boxOffice' => 2_885_439_100,
            'isActive' => true,
            'rank' => 2,
        ]], [
            'tmdb-299534' => [[
                'key' => 'tmdb-299534',
                'observedAt' => '2026-09-29T09:00:00+09:00',
                'boxOffice' => 2_885_439_100,
                'isActive' => true,
            ]],
        ], [
            'tmdb-299534' => [
                'releaseDate' => '2019-04-24',
                'releaseDatePrecision' => 'day',
                'currentReleaseDate' => '2026-09-25',
            ],
        ], $now);

        $movie = $result['movies']['tmdb-299534'];
        $this->assertTrue($movie['onBoard']);
        $this->assertSame('2019-04-24', $movie['releaseDate']);
        $this->assertSame('2026-09-25', $movie['currentReleaseDate']);
        $this->assertSame(4, $movie['daysSinceRelease']);
    }
}
