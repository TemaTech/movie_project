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
        ], true);

        $this->assertStringContainsString('movie-chart', $svg);
        $this->assertStringContainsString('50.0億円', $svg);
        $this->assertStringContainsString('8/1', $svg);
        $this->assertStringContainsString('8/10', $svg);
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
