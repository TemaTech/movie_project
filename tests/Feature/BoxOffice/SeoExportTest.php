<?php

namespace Tests\Feature\BoxOffice;

use App\Console\Commands\ExportStaticSite;
use ReflectionMethod;
use Tests\TestCase;

class SeoExportTest extends TestCase
{
    public function test_ranking_fallback_stays_available_without_javascript(): void
    {
        $guard = $this->invoke('rankingFallbackGuard');

        $this->assertStringContainsString("document.documentElement.classList.add('js')", $guard);
        $this->assertStringContainsString('html.js .ranking-static,html.js .now-static{display:none}', $guard);
        $this->assertStringNotContainsString('hidden', $this->invoke('rankingStaticHtml', $this->movies(1), false, null));
    }

    public function test_ranking_html_lists_every_ranked_movie_with_links(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $html = $this->invoke('rankingStaticHtml', $this->movies(101), false, '2026-10-24T12:00:00+09:00');
        $json = $this->invoke('rankingItemListJson', $this->movies(101), false);

        $this->assertStringContainsString('<h1 class="page-title">世界映画興行収入ランキング</h1>', $html);
        $this->assertStringContainsString('1位は『作品1』の1億円（2026年10月24日更新）。', $html);
        $this->assertStringContainsString('href="/movies/movie-1/"', $html);
        $this->assertStringContainsString('href="/movies/movie-100/"', $html);
        $this->assertStringContainsString('href="/movies/movie-101/"', $html);
        $this->assertStringContainsString('href="/japan/">日本映画興行収入ランキング', $html);
        $this->assertStringContainsString('https://mubiran.com/movies/movie-101/', $json);
    }

    public function test_japan_and_world_rankings_have_separate_canonicals(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $movies = $this->movies(1);

        $japan = $this->invoke('indexMeta', '/japan/', $movies, '2026-10-24T12:00:00+09:00');
        $this->assertSame('日本映画興行収入ランキング | MUBIRAN', $japan['title']);
        $this->assertSame('https://mubiran.com/japan/', $japan['canonical']);
        $this->assertStringContainsString('日本映画の歴代興行収入ランキング。1位は『作品1』の1億円', $japan['description']);
        $this->assertStringContainsString('<h1 class="page-title">日本映画興行収入ランキング</h1>', $japan['appHtml']);
        $this->assertStringContainsString('href="/">世界映画興行収入ランキング', $japan['appHtml']);
        $this->assertStringContainsString('ItemList', $japan['jsonLd']);
        $this->assertStringContainsString('https://mubiran.com/movies/movie-1/', $japan['jsonLd']);

        $world = $this->invoke('indexMeta', '/', $movies, '2026-10-24T12:00:00+09:00');
        $this->assertSame('世界映画興行収入ランキング | MUBIRAN', $world['title']);
        $this->assertSame('https://mubiran.com/', $world['canonical']);
        $this->assertStringContainsString('href="/movies/movie-1/"', $world['appHtml']);
    }

    public function test_now_pages_embed_the_same_movies_users_see(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $payload = [
            'board' => [[
                'key' => 'jp-tmdb-1',
                'slug' => 'jp-tmdb-1',
                'title' => '公開中作品',
                'delta' => 340_000_000,
                'deltaLabel' => '+3.4億円',
                'revenueLabel' => '184.9億円',
                'boxOffice' => 18_490_000_000,
                'daysSincePrev' => 7,
                'dailyPaceLabel' => '1日0.5億円',
                'rank' => 12,
                'daysSinceRelease' => 99,
                'periodGrowth' => [['label' => '1週間で+3.4億円']],
                'sparkline' => [
                    ['at' => '2026-10-01T00:00:00+09:00', 'boxOffice' => 18_000_000_000],
                    ['at' => '2026-10-24T00:00:00+09:00', 'boxOffice' => 18_490_000_000],
                ],
            ], [
                'key' => 'jp-tmdb-2',
                'slug' => 'jp-tmdb-2',
                'title' => '発表待ち作品',
                'delta' => null,
                'revenueLabel' => '10.0億円',
                'boxOffice' => 1_000_000_000,
                'daysSinceRelease' => 5,
            ]],
            'today' => [],
            'milestones' => [],
            'disclaimer' => '日本の興行収入は配給会社の発表ベースです。数字は毎日は動きません。伸びは前回の発表との差です。',
        ];
        $now = $this->invoke('indexMeta', '/now/', [], null, $payload);

        $this->assertSame('公開中映画の興行収入の勢い（日本） | MUBIRAN', $now['title']);
        $this->assertStringContainsString('now-static', $now['appHtml']);
        $this->assertStringContainsString('<h1 class="page-title now-page-title">公開中映画の勢い（日本）</h1>', $now['appHtml']);
        $this->assertStringContainsString('href="/movies/jp-tmdb-1/"', $now['appHtml']);
        $this->assertStringContainsString('href="/movies/jp-tmdb-2/"', $now['appHtml']);
        $this->assertStringContainsString('公開中作品', $now['appHtml']);
        $this->assertStringContainsString('+3.4億円', $now['appHtml']);
        $this->assertStringContainsString('発表待ち作品', $now['appHtml']);
        $this->assertStringContainsString('href="/now/global/"', $now['appHtml']);
        $this->assertStringContainsString('ItemList', $now['jsonLd']);
        $this->assertStringContainsString('https://mubiran.com/movies/jp-tmdb-1/', $now['jsonLd']);
        $this->assertStringNotContainsString('世界映画興行収入ランキング', $now['appHtml']);
    }

    public function test_robots_allows_ranking_json_without_crawl_delay(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $robots = $this->invoke('robotsTxt');

        $this->assertStringContainsString('Allow: /data/movies.json', $robots);
        $this->assertStringContainsString('Allow: /data/now-playing.json', $robots);
        $this->assertStringContainsString('Disallow: /data/', $robots);
        $this->assertStringContainsString('Sitemap: https://mubiran.com/sitemap.xml', $robots);
        $this->assertStringNotContainsString('Disallow: /storage/', $robots);
        $this->assertStringNotContainsString('Crawl-delay', $robots);
    }

    public function test_movie_page_points_to_the_other_ranking_of_the_same_film(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $html = $this->invoke('moviePageHtml', [
            'id' => 'tmdb-19995',
            'title' => 'アバター',
            'revenue' => '29.24億ドル',
            'revenueYen' => '4386億円',
            'rank' => 2,
            'updatedAt' => '2026-10-24T03:00:00+09:00',
            'posterUrl' => '',
            'genres' => [],
        ], false, sys_get_temp_dir(), ['tmdb-19995' => true, 'jp-tmdb-19995' => true]);

        $this->assertStringContainsString('rel="related"', $html);
        $this->assertStringContainsString('https://mubiran.com/movies/jp-tmdb-19995/', $html);
        $this->assertStringContainsString('href="/movies/jp-tmdb-19995/" hidden', $html);
        $this->assertStringContainsString('"sameAs":"https://mubiran.com/movies/jp-tmdb-19995/"', $html);
        $this->assertStringNotContainsString('世界ランキングの同作品', $html);
        $this->assertStringContainsString('日本ランキングの同作品', $html);
    }

    public function test_movie_page_shows_the_last_day_box_office_increased(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $html = $this->invoke('moviePageHtml', [
            'id' => 'endgame',
            'title' => 'エンドゲーム',
            'revenue' => '29.39億ドル',
            'revenueYen' => '4408.5億円',
            'rank' => 1,
            'updatedAt' => '2026-10-24T03:00:00+09:00',
            'pageHistory' => [
                'grossIncreasedAt' => '2026-10-22T03:00:00+09:00',
            ],
            'posterUrl' => '',
            'genres' => [],
        ], false, sys_get_temp_dir());

        $this->assertMatchesRegularExpression(
            '/<dt>興行収入<\/dt><dd>29\.39億ドル<\/dd><\/div>\s*<div><dt>日本換算<\/dt><dd>4408\.5億円<\/dd><\/div>\s*<div><dt>興行が増えた日<\/dt><dd>2026年10月22日<\/dd>/',
            $html,
        );
        $this->assertStringNotContainsString('最終更新', $html);
        $this->assertStringNotContainsString('2026年10月24日', $html);
        $this->assertStringNotContainsString(
            '興行が増えた日',
            $this->invoke('moviePageHtml', [
                'id' => 'undated',
                'title' => '日付なし',
                'revenue' => '1億円',
                'revenueYen' => null,
                'rank' => 2,
                'updatedAt' => '2026-10-24T03:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], true, sys_get_temp_dir()),
        );
    }

    public function test_sitemap_includes_japan_and_movie_lastmod(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = sys_get_temp_dir().'/mubiran-sitemap-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->invoke('exportSitemap', $dir, [
                ['id' => 'g1', 'updatedAt' => '2026-08-01T00:00:00+09:00'],
            ], [
                ['id' => 'jp-1'],
            ], []);
            $xml = file_get_contents($dir.'/sitemap.xml');
        } finally {
            @unlink($dir.'/sitemap.xml');
            @rmdir($dir);
        }

        $this->assertIsString($xml);
        $this->assertStringContainsString('https://mubiran.com/japan/', $xml);
        $this->assertStringContainsString('https://mubiran.com/movies/g1/', $xml);
        $this->assertStringContainsString('https://mubiran.com/movies/jp-1/', $xml);
        $this->assertStringContainsString('<lastmod>2026-08-01T00:00:00+09:00</lastmod>', $xml);
        $this->assertSame('fallback', $this->invoke('movieLastmod', [], 'fallback'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movies(int $count): array
    {
        $movies = [];
        for ($index = 1; $index <= $count; $index++) {
            $movies[] = [
                'id' => 'movie-'.$index,
                'slug' => 'movie-'.$index,
                'rank' => $index,
                'title' => '作品'.$index,
                'revenue' => '1億円',
                'genres' => ['ドラマ'],
            ];
        }

        return $movies;
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ExportStaticSite::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->app->make(ExportStaticSite::class), ...$arguments);
    }
}
