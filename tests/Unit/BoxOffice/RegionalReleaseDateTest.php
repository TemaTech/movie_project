<?php

namespace Tests\Unit\BoxOffice;

use App\Services\BoxOffice\RegionalReleaseDate;
use Tests\TestCase;

class RegionalReleaseDateTest extends TestCase
{
    public function test_japan_pages_prefer_the_stored_japanese_theatrical_date(): void
    {
        $this->assertSame(
            '2026-07-03',
            RegionalReleaseDate::displayed('2026-07-03', 'day', '2026-06-17', true),
        );
    }

    public function test_global_pages_keep_the_tmdb_primary_date(): void
    {
        $this->assertSame(
            '2026-06-17',
            RegionalReleaseDate::displayed('2026-07-03', 'day', '2026-06-17', false),
        );
    }

    public function test_year_precision_japan_dates_do_not_replace_tmdb(): void
    {
        $this->assertSame(
            '2019-07-12',
            RegionalReleaseDate::displayed('2019-01-01', 'year', '2019-07-12', true),
        );
    }

    public function test_japan_falls_back_to_tmdb_when_stored_date_is_missing(): void
    {
        $this->assertSame(
            '2012-07-12',
            RegionalReleaseDate::displayed(null, 'year', '2012-07-12', true),
        );
    }

    public function test_stored_prefers_the_chart_history_date(): void
    {
        [$date, $precision] = RegionalReleaseDate::stored([
            'releaseDate' => '2026-06-17',
            'releaseDatePrecision' => 'day',
            'pageHistory' => [
                'releaseDate' => '2026-07-03',
                'releaseDatePrecision' => 'day',
            ],
        ]);

        $this->assertSame('2026-07-03', $date);
        $this->assertSame('day', $precision);
    }
}
