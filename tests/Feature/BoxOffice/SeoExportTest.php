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
        $this->assertStringContainsString('html.js .ranking-static{display:none}', $guard);
        $this->assertStringNotContainsString('hidden', $this->invoke('rankingStaticHtml', $this->movies(1), false, null));
    }

    public function test_ranking_html_lists_the_first_page_with_movie_links(): void
    {
        $html = $this->invoke('rankingStaticHtml', $this->movies(101), false, '2026-10-24T12:00:00+09:00');

        $this->assertStringContainsString('<h1 class="page-title">世界映画興行収入ランキング</h1>', $html);
        $this->assertStringContainsString('1位は『作品1』の1億円（2026年10月24日更新）。', $html);
        $this->assertStringContainsString('href="/movies/movie-1/"', $html);
        $this->assertStringContainsString('href="/movies/movie-100/"', $html);
        $this->assertStringNotContainsString('/movies/movie-101/', $html);
        $this->assertStringContainsString('href="/japan/">日本映画興行収入ランキング', $html);
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

    public function test_now_pages_do_not_embed_the_ranking_list(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $now = $this->invoke('indexMeta', '/now/', $this->movies(2), '2026-10-24T12:00:00+09:00');

        $this->assertSame('公開中映画の興行収入の勢い（日本） | MUBIRAN', $now['title']);
        $this->assertSame('', $now['appHtml']);
        $this->assertSame('', $now['jsonLd']);
    }

    public function test_robots_allows_ranking_json_without_crawl_delay(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $robots = $this->invoke('robotsTxt');

        $this->assertStringContainsString('Allow: /data/movies.json', $robots);
        $this->assertStringContainsString('Allow: /data/now-playing.json', $robots);
        $this->assertStringContainsString('Disallow: /data/', $robots);
        $this->assertStringContainsString('Sitemap: https://mubiran.com/sitemap.xml', $robots);
        $this->assertStringNotContainsString('Crawl-delay', $robots);
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
