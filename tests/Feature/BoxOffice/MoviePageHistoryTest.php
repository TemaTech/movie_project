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

        $this->assertStringContainsString('movie-chart-scroll', $svg);
        $this->assertStringContainsString('class="movie-chart-y"', $svg);
        $this->assertStringContainsString('横に動かすと続きが見られます。', $svg);
        $this->assertStringContainsString('当日', $svg);
        $this->assertStringContainsString('9日', $svg);
        $this->assertStringContainsString('公開9日', $svg);
        $this->assertStringContainsString('movie-chart-tip', $svg);
        $this->assertStringContainsString('data-y-zoomed="1"', $svg);
        $this->assertDoesNotMatchRegularExpression('/class="movie-chart-guide-label">0億円</', $svg);
        $this->assertMatchesRegularExpression('/class="movie-chart-guide-label">50億円</', $svg);
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
        $this->assertStringContainsString('data-y-zoomed="1"', $globalSvg);
        $this->assertDoesNotMatchRegularExpression('/class="movie-chart-guide-label">0億ドル</', $globalSvg);
        $this->assertMatchesRegularExpression('/class="movie-chart-guide-label">5億ドル</', $globalSvg);
        $this->assertDoesNotMatchRegularExpression('/class="movie-chart-guide-label">[^<]*\.\d/', $globalSvg);

        $wide = $this->invoke($command, 'trajectorySvg', [
            ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 1_000_000_000],
            ['at' => '2026-08-10T03:00:00+09:00', 'boxOffice' => 8_000_000_000],
        ], true, '2026-08-01');
        $this->assertDoesNotMatchRegularExpression('/data-y-zoomed=/', $wide);
        $this->assertMatchesRegularExpression('/class="movie-chart-guide-label">0億円</', $wide);

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

    public function test_trajectory_svg_zooms_when_totals_stay_in_a_narrow_band(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $points = [
            ['at' => '2026-10-01T03:00:00+09:00', 'boxOffice' => 2_790_000_000],
            ['at' => '2026-10-02T03:00:00+09:00', 'boxOffice' => 2_811_000_000],
            ['at' => '2026-10-06T03:00:00+09:00', 'boxOffice' => 2_885_000_000],
            ['at' => '2026-10-10T03:00:00+09:00', 'boxOffice' => 2_890_000_000],
            ['at' => '2026-10-11T03:00:00+09:00', 'boxOffice' => 2_920_000_000],
            ['at' => '2026-10-12T03:00:00+09:00', 'boxOffice' => 2_926_000_000],
            ['at' => '2026-10-18T03:00:00+09:00', 'boxOffice' => 2_927_000_000],
            ['at' => '2026-10-24T03:00:00+09:00', 'boxOffice' => 2_939_000_000],
        ];
        $svg = $this->invoke($command, 'trajectorySvg', $points, false, '2026-10-01');

        $this->assertStringContainsString('data-y-zoomed="1"', $svg);
        $this->assertStringContainsString('変化が見える範囲に拡大', $svg);
        foreach (['0億ドル', '5億ドル', '10億ドル', '15億ドル', '20億ドル', '25億ドル'] as $hidden) {
            $this->assertStringNotContainsString('class="movie-chart-guide-label">'.$hidden, $svg);
        }
        $this->assertStringContainsString('class="movie-chart-guide-label">28億ドル', $svg);
        $this->assertStringContainsString('class="movie-chart-guide-label">29億ドル', $svg);

        preg_match_all(
            '/<text x="([0-9.]+)" y="([0-9.]+)" text-anchor="(?:start|middle|end)" class="movie-chart-point-label">([^<]+)/',
            $svg,
            $labels,
            PREG_SET_ORDER,
        );
        $shown = array_column($labels, 3);
        $this->assertContains('27.90億', $shown);
        $this->assertContains('29.39億', $shown);
        $this->assertLessThan(count($points), count($shown));
        for ($i = 0; $i < count($labels); $i++) {
            for ($j = $i + 1; $j < count($labels); $j++) {
                $dx = abs((float) $labels[$i][1] - (float) $labels[$j][1]);
                $dy = abs((float) $labels[$i][2] - (float) $labels[$j][2]);
                $this->assertTrue(
                    $dx >= 46 || $dy >= 16,
                    $labels[$i][3].' と '.$labels[$j][3].' が重なっています',
                );
            }
        }

        $html = $this->invoke($command, 'trajectorySection', [
            'title' => 'エンドゲーム',
            'isActive' => false,
            'pageHistory' => [
                'sparkline' => $points,
                'releaseDate' => '2026-10-01',
                'releaseDatePrecision' => 'day',
            ],
        ], false);
        $this->assertStringContainsString('縦軸は変化が見える範囲に拡大しています。', $html);
        $this->assertStringNotContainsString('横軸は公開からの日数', $html);
        $this->assertStringNotContainsString('点に触れると', $html);
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

    public function test_trajectory_section_includes_day_and_total_table(): void
    {
        $command = $this->app->make(ExportStaticSite::class);
        $html = $this->invoke($command, 'trajectorySection', [
            'title' => 'テスト作品',
            'isActive' => false,
            'pageHistory' => [
                'sparkline' => [
                    ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 4_000_000_000],
                    ['at' => '2026-08-10T03:00:00+09:00', 'boxOffice' => 4_100_000_000],
                    ['at' => '2026-08-20T03:00:00+09:00', 'boxOffice' => 6_000_000_000],
                ],
                'releaseDate' => '2026-08-01',
                'releaseDatePrecision' => 'day',
            ],
        ], true);

        $this->assertStringContainsString('movie-chart-table', $html);
        $this->assertStringContainsString('増分は前回発表との差。', $html);
        $this->assertStringNotContainsString('日数と累計の対応です', $html);
        $this->assertStringContainsString('公開日数', $html);
        $this->assertStringContainsString('>当日<', $html);
        $this->assertStringContainsString('>9日<', $html);
        $this->assertStringContainsString('>19日<', $html);
        $this->assertStringContainsString('40.0億円', $html);
        $this->assertStringContainsString('41.0億円', $html);
        $this->assertStringContainsString('60.0億円', $html);
        $this->assertStringContainsString('+1.0億円', $html);
        $this->assertStringContainsString('+19.0億円', $html);
        preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $body);
        $this->assertMatchesRegularExpression('/^<tr class="is-latest">.*?>19日</s', $body[1]);
        $this->assertMatchesRegularExpression('/>19日<.*?>9日<.*?>当日</s', $body[1]);

        $elapsed = $this->invoke($command, 'trajectorySection', [
            'title' => '年のみ',
            'isActive' => false,
            'pageHistory' => [
                'sparkline' => [
                    ['at' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 400_000_000],
                    ['at' => '2026-08-04T03:00:00+09:00', 'boxOffice' => 410_000_000],
                ],
                'releaseDate' => '2026',
                'releaseDatePrecision' => 'year',
            ],
        ], false);

        $this->assertStringContainsString('横軸は最初の観測からの日数です。', $elapsed);
        $this->assertStringContainsString('経過日数', $elapsed);
        $this->assertStringContainsString('>初日<', $elapsed);
        $this->assertStringContainsString('>3日<', $elapsed);
        preg_match('/<tbody>(.*?)<\/tbody>/s', $elapsed, $elapsedBody);
        $this->assertMatchesRegularExpression('/^<tr class="is-latest">.*?>3日<.*?>初日</s', $elapsedBody[1]);
        $this->assertStringContainsString('4.00億ドル', $elapsed);
        $this->assertStringContainsString('4.10億ドル', $elapsed);
        $this->assertStringContainsString('+0.10億ドル', $elapsed);
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
