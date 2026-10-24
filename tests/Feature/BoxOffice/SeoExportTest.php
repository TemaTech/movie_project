<?php

namespace Tests\Feature\BoxOffice;

use App\Console\Commands\ExportStaticSite;
use ReflectionMethod;
use Tests\TestCase;

class SeoExportTest extends TestCase
{
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

    public function test_movie_page_lead_and_neighbor_links(): void
    {
        $movie = [
            'title' => '作品2',
            'revenue' => '20.0億円',
            'rank' => 2,
            'updatedAt' => '2026-10-24T03:00:00+09:00',
            'genres' => ['アニメ'],
            'rankAbove' => ['title' => '作品1', 'slug' => 'movie-1', 'rank' => 1, 'revenue' => '30億円'],
            'rankBelow' => ['title' => '作品3', 'slug' => 'movie-3', 'rank' => 3, 'revenue' => '10億円'],
            'genrePeers' => [
                ['title' => '作品1', 'slug' => 'movie-1', 'rank' => 1, 'revenue' => '30億円'],
            ],
        ];

        $this->assertSame(
            '2026年10月24日時点の日本ランキングは第2位、興行収入は20.0億円。',
            $this->invoke('movieLeadSentence', $movie, true),
        );
        $this->assertSame(
            '2026年10月24日時点の興行収入は5億円。',
            $this->invoke('movieLeadSentence', [
                'revenue' => '5億円',
                'rank' => '圏外',
                'updatedAt' => '2026-10-24T00:00:00+09:00',
            ], true),
        );

        $html = $this->invoke('movieRankingLinksHtml', $movie);
        $this->assertStringContainsString('前後の順位', $html);
        $this->assertStringContainsString('href="/movies/movie-1/"', $html);
        $this->assertStringContainsString('href="/movies/movie-3/"', $html);
        $this->assertStringContainsString('アニメの上位', $html);
        $this->assertSame('', $this->invoke('movieRankingLinksHtml', ['title' => '圏外作', 'genres' => []]));
    }

    public function test_genre_peers_are_higher_ranked_movies_with_the_same_first_genre(): void
    {
        $linked = $this->invoke('withRankingLinks', [
            ['title' => 'A', 'slug' => 'a', 'rank' => 1, 'revenue' => '1', 'genres' => ['アニメ']],
            ['title' => 'B', 'slug' => 'b', 'rank' => 2, 'revenue' => '1', 'genres' => ['アニメ']],
            ['title' => 'C', 'slug' => 'c', 'rank' => 3, 'revenue' => '1', 'genres' => ['アニメ']],
            ['title' => 'D', 'slug' => 'd', 'rank' => 4, 'revenue' => '1', 'genres' => ['アニメ']],
            ['title' => 'E', 'slug' => 'e', 'rank' => 5, 'revenue' => '1', 'genres' => ['ドラマ']],
            ['title' => 'F', 'slug' => 'f', 'rank' => 6, 'revenue' => '1', 'genres' => ['アニメ']],
        ]);

        $this->assertNull($linked[0]['rankAbove']);
        $this->assertSame('B', $linked[0]['rankBelow']['title']);
        $this->assertSame([], $linked[0]['genrePeers']);
        $this->assertSame('E', $linked[5]['rankAbove']['title']);
        $this->assertSame(['A', 'B', 'C'], array_column($linked[5]['genrePeers'], 'title'));
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
