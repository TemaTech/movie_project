<?php

namespace Tests\Feature\BoxOffice;

use App\Console\Commands\ExportStaticSite;
use ReflectionMethod;
use Tests\TestCase;

class MoviePageHistoryTest extends TestCase
{
    public function test_trajectory_svg_requires_two_distinct_points(): void
    {
        $command = $this->app->make(ExportStaticSite::class);

        $this->assertSame('', $this->invoke($command, 'trajectorySvg', [[
            'at' => '2026-08-01T03:00:00+09:00',
            'boxOffice' => 4_000_000_000,
        ]], true));

        $this->assertSame('', $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 5_000_000_000],
            ['at' => '2026-08-10T03:00:00+09:00', 'boxOffice' => 5_000_000_000],
        ], true));

        $svg = $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 4_000_000_000],
            ['at' => '2026-08-10T03:00:00+09:00', 'boxOffice' => 6_000_000_000],
        ], true, '2026-08-01');

        $this->assertStringContainsString('movie-chart', $svg);
        $this->assertStringContainsString('当日', $svg);
        $this->assertStringContainsString('9日', $svg);
        $this->assertStringContainsString('公開9日', $svg);
        $this->assertStringContainsString('movie-chart-tip', $svg);
        $this->assertMatchesRegularExpression('/class="movie-chart-guide-label">0億円</', $svg);
        $this->assertDoesNotMatchRegularExpression('/class="movie-chart-guide-label">[^<]*\.\d/', $svg);
        $this->assertMatchesRegularExpression(
            '/<text x="70\.0" y="[0-9.]+" text-anchor="start" class="movie-chart-point-label">/',
            $svg,
        );
        $this->assertMatchesRegularExpression(
            '/<text x="622\.0" y="[0-9.]+" text-anchor="end" class="movie-chart-point-label">/',
            $svg,
        );

        $globalSvg = $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 400_000_000],
            ['at' => '2026-08-10T03:00:00+09:00', 'boxOffice' => 600_000_000],
        ], false, '2026-08-01');
        $this->assertMatchesRegularExpression('/class="movie-chart-guide-label">0億ドル</', $globalSvg);
        $this->assertDoesNotMatchRegularExpression('/class="movie-chart-guide-label">[^<]*\.\d/', $globalSvg);

        $clustered = $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 4_000_000_000],
            ['at' => '2026-08-03T03:00:00+09:00', 'boxOffice' => 4_500_000_000],
            ['at' => '2026-08-20T03:00:00+09:00', 'boxOffice' => 6_000_000_000],
            ['at' => '2026-08-22T03:00:00+09:00', 'boxOffice' => 6_200_000_000],
        ], true, '2026-08-01');
        $axisDays = [];
        if (preg_match_all('/>(当日|\d+日)<\/text>/', $clustered, $matches)) {
            $axisDays = $matches[1];
        }
        $this->assertNotContains('2日', $axisDays);
        $this->assertContains('当日', $axisDays);
        $this->assertContains('21日', $axisDays);
    }

    public function test_trajectory_svg_collapses_display_equal_totals(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $svg = $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-17T09:49:11+09:00', 'boxOffice' => 2_021_832_000],
            ['at' => '2026-08-31T21:08:06+09:00', 'boxOffice' => 2_332_508_000],
            ['at' => '2026-09-01T09:21:14+09:00', 'boxOffice' => 2_333_326_398],
        ], false, '2026-07-29');

        preg_match_all('/class="movie-chart-point-label">([^<]+)/', $svg, $labels);
        $this->assertSame(['20.22億', '23.33億'], $labels[1]);
        $this->assertStringContainsString('公開33日', $svg);
        $this->assertStringNotContainsString('公開34日', $svg);
    }

    public function test_movie_history_html_hides_next_target_for_first_place(): void
    {
        $command = $this->app->make(ExportStaticSite::class);

        $first = $this->invoke($command, 'movieHistoryHtml', [
            'momentum' => ['hasHistory' => false],
            'pageHistory' => [
                'sparkline' => [],
                'milestones' => [],
                'nextToOvertake' => null,
            ],
        ], true);
        $this->assertSame('', $first);

        $second = $this->invoke($command, 'movieHistoryHtml', [
            'momentum' => ['hasHistory' => false],
            'pageHistory' => [
                'sparkline' => [],
                'milestones' => [],
                'nextToOvertake' => [
                    'key' => 'jp-first',
                    'title' => '南極物語',
                    'rank' => 41,
                    'remainingLabel' => '2.1億円',
                ],
            ],
        ], true);

        $this->assertStringContainsString('次に抜く作品', $second);
        $this->assertStringContainsString('/movies/jp-first/', $second);
        $this->assertStringContainsString('南極物語', $second);
        $this->assertStringContainsString('第41位', $second);
        $this->assertStringContainsString('発表ベースの記録です', $second);
    }

    public function test_footer_includes_tmdb_disclaimer_and_wikipedia_license(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $footer = $this->invoke($command, 'siteFooterInnerHtml');

        $this->assertStringContainsString('/images/tmdb-logo.svg', $footer);
        $this->assertStringContainsString('not endorsed, certified, or otherwise approved by TMDB', $footer);
        $this->assertStringContainsString('CC BY-SA 4.0', $footer);
        $this->assertStringContainsString('1ドル=150円', $footer);
    }

    public function test_client_payload_strips_page_history(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $payload = $this->invoke($command, 'moviesForClientPayload', [[
            'id' => 'jp-a',
            'title' => 'テスト',
            'pageHistory' => ['sparkline' => [1]],
            'momentum' => ['hasHistory' => true],
        ]]);

        $this->assertArrayNotHasKey('pageHistory', $payload[0]);
        $this->assertTrue($payload[0]['momentum']['hasHistory']);
    }

    private function invoke(ExportStaticSite $command, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($command, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, ...$arguments);
    }
}
