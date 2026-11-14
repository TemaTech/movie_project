<?php

namespace Tests\Feature\BoxOffice;

use App\Console\Commands\ExportStaticSite;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class SeoExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureViteManifest();
    }

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

    public function test_japan_movie_page_shows_the_stored_japanese_release_date(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = sys_get_temp_dir().'/mubiran-jp-date-'.bin2hex(random_bytes(4));
        mkdir($dir.'/data/details', 0777, true);
        file_put_contents($dir.'/data/details/jp-tmdb-1084244.json', json_encode([
            'title' => 'トイ・ストーリー５',
            'original_title' => 'Toy Story 5',
            'release_date' => '2026-06-17',
        ], JSON_UNESCAPED_UNICODE));

        try {
            $html = $this->invoke('moviePageHtml', [
                'id' => 'jp-tmdb-1084244',
                'title' => 'トイ・ストーリー5',
                'originalTitle' => 'Toy Story 5',
                'revenue' => '130.4億円',
                'revenueYen' => null,
                'rank' => 34,
                'releaseDate' => '2026-06-17',
                'releaseDatePrecision' => 'day',
                'pageHistory' => [
                    'releaseDate' => '2026-07-03',
                    'releaseDatePrecision' => 'day',
                ],
                'updatedAt' => '2026-10-14T21:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], true, $dir);
        } finally {
            @unlink($dir.'/data/details/jp-tmdb-1084244.json');
            @rmdir($dir.'/data/details');
            @rmdir($dir.'/data');
            @rmdir($dir);
        }

        $this->assertStringContainsString('<dt>公開日</dt><dd>2026年7月3日</dd>', $html);
        $this->assertStringNotContainsString('2026年6月17日', $html);
        $this->assertStringContainsString('"datePublished":"2026-07-03"', $html);
        $this->assertStringContainsString('2026年7月3日公開。', $html);
    }

    public function test_global_movie_page_keeps_the_tmdb_primary_release_date(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = sys_get_temp_dir().'/mubiran-global-date-'.bin2hex(random_bytes(4));
        mkdir($dir.'/data/details', 0777, true);
        file_put_contents($dir.'/data/details/tmdb-1084244.json', json_encode([
            'title' => 'トイ・ストーリー５',
            'original_title' => 'Toy Story 5',
            'release_date' => '2026-06-17',
        ], JSON_UNESCAPED_UNICODE));

        try {
            $html = $this->invoke('moviePageHtml', [
                'id' => 'tmdb-1084244',
                'title' => 'トイ・ストーリー５',
                'originalTitle' => 'Toy Story 5',
                'revenue' => '11.48億ドル',
                'revenueYen' => '1,722.0億円',
                'rank' => 36,
                'releaseDate' => '2026-07-03',
                'releaseDatePrecision' => 'day',
                'updatedAt' => '2026-10-12T03:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], false, $dir);
        } finally {
            @unlink($dir.'/data/details/tmdb-1084244.json');
            @rmdir($dir.'/data/details');
            @rmdir($dir.'/data');
            @rmdir($dir);
        }

        $this->assertStringContainsString('<dt>公開日</dt><dd>2026年6月17日</dd>', $html);
        $this->assertStringNotContainsString('2026年7月3日', $html);
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

    public function test_sitemap_uses_data_dates_and_keeps_the_url_set(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        Carbon::setTestNow('2026-11-14T12:00:00+09:00');
        $dir = sys_get_temp_dir().'/mubiran-sitemap-'.bin2hex(random_bytes(4));
        mkdir($dir);

        $global = [[
            'id' => 'tmdb-299534',
            'updatedAt' => '2026-10-29T03:00:00+09:00',
            'pageHistory' => [
                'grossIncreasedAt' => '2026-10-28T03:00:00+09:00',
            ],
        ]];
        $japan = [[
            'id' => 'jp-tmdb-280',
            'updatedAt' => '2026-08-31T21:00:00+09:00',
        ], [
            'id' => 'jp-tmdb-1545621',
        ]];
        $boardOnly = [[
            'id' => 'jp-board-only',
            'pageHistory' => [
                'grossIncreasedAt' => '2026-09-15T12:00:00+09:00',
            ],
        ]];
        $japanInsights = [
            'board' => [
                ['key' => 'jp-tmdb-280'],
                ['key' => 'jp-board-only'],
            ],
            'today' => [[
                'key' => 'jp-tmdb-280',
                'lastChangeAt' => '2026-08-31T21:00:00+09:00',
            ]],
            'milestones' => [],
        ];
        $globalInsights = [
            'board' => [['key' => 'tmdb-299534']],
            'today' => [],
            'milestones' => [[
                'key' => 'tmdb-299534',
                'reachedAt' => '2026-10-20T03:00:00+09:00',
            ]],
        ];

        try {
            $this->invoke(
                'exportSitemap',
                $dir,
                $global,
                $japan,
                $boardOnly,
                '2026-10-29 03:00:00',
                '2026-08-31 21:00:00',
                $globalInsights,
                $japanInsights,
            );
            $xml = file_get_contents($dir.'/sitemap.xml');
            $this->invoke(
                'exportSitemap',
                $dir,
                $global,
                $japan,
                $boardOnly,
                '2026-10-29 03:00:00',
                '2026-08-31 21:00:00',
                $globalInsights,
                $japanInsights,
            );
            $xmlAgain = file_get_contents($dir.'/sitemap.xml');
        } finally {
            Carbon::setTestNow();
            @unlink($dir.'/sitemap.xml');
            @rmdir($dir);
        }

        $this->assertIsString($xml);
        $this->assertSame($xml, $xmlAgain);
        $this->assertStringNotContainsString('2026-11-14', $xml);

        $entries = $this->sitemapEntries($xml);
        $this->assertSame([
            'https://mubiran.com/',
            'https://mubiran.com/japan/',
            'https://mubiran.com/now/',
            'https://mubiran.com/now/global/',
            'https://mubiran.com/feed.xml',
            'https://mubiran.com/about/',
            'https://mubiran.com/privacy/',
            'https://mubiran.com/movies/tmdb-299534/',
            'https://mubiran.com/movies/jp-tmdb-280/',
            'https://mubiran.com/movies/jp-tmdb-1545621/',
            'https://mubiran.com/movies/jp-board-only/',
        ], array_keys($entries));

        $this->assertSame('2026-10-28T03:00:00+09:00', $entries['https://mubiran.com/']);
        $this->assertSame('2026-08-31T21:00:00+09:00', $entries['https://mubiran.com/japan/']);
        $this->assertSame('2026-09-15T12:00:00+09:00', $entries['https://mubiran.com/now/']);
        $this->assertSame('2026-10-28T03:00:00+09:00', $entries['https://mubiran.com/now/global/']);
        $this->assertSame('2026-10-20T03:00:00+09:00', $entries['https://mubiran.com/feed.xml']);
        $this->assertNull($entries['https://mubiran.com/about/']);
        $this->assertNull($entries['https://mubiran.com/privacy/']);
        $this->assertSame('2026-10-28T03:00:00+09:00', $entries['https://mubiran.com/movies/tmdb-299534/']);
        $this->assertSame('2026-08-31T21:00:00+09:00', $entries['https://mubiran.com/movies/jp-tmdb-280/']);
        $this->assertNull($entries['https://mubiran.com/movies/jp-tmdb-1545621/']);
        $this->assertSame('2026-09-15T12:00:00+09:00', $entries['https://mubiran.com/movies/jp-board-only/']);
        $this->assertNull($this->invoke('movieAsOfTimestamp', []));
    }

    public function test_sitemap_movie_lastmod_matches_json_ld_date_modified(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $cases = [
            [
                'id' => 'tmdb-299534',
                'isJapan' => false,
                'movie' => [
                    'id' => 'tmdb-299534',
                    'title' => 'アベンジャーズ／エンドゲーム',
                    'revenue' => '29.42億ドル',
                    'revenueYen' => '4,413.3億円',
                    'rank' => 1,
                    'updatedAt' => '2026-10-29T03:00:00+09:00',
                    'pageHistory' => [
                        'grossIncreasedAt' => '2026-10-28T03:00:00+09:00',
                    ],
                    'posterUrl' => '',
                    'genres' => [],
                ],
            ],
            [
                'id' => 'jp-tmdb-280',
                'isJapan' => true,
                'movie' => [
                    'id' => 'jp-tmdb-280',
                    'title' => 'ターミネーター2',
                    'revenue' => '87.9億円',
                    'revenueYen' => null,
                    'rank' => 81,
                    'posterUrl' => '',
                    'genres' => [],
                ],
            ],
            [
                'id' => 'jp-tmdb-1545621',
                'isJapan' => true,
                'movie' => [
                    'id' => 'jp-tmdb-1545621',
                    'title' => '名探偵コナン ハイウェイの堕天使',
                    'revenue' => '137.0億円',
                    'revenueYen' => null,
                    'rank' => 28,
                    'updatedAt' => '2026-08-31T21:00:00+09:00',
                    'posterUrl' => '',
                    'genres' => [],
                ],
            ],
        ];

        $dir = sys_get_temp_dir().'/mubiran-sitemap-ld-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->invoke('exportSitemap', $dir, [
                $cases[0]['movie'],
            ], [
                $cases[1]['movie'],
                $cases[2]['movie'],
            ]);
            $entries = $this->sitemapEntries((string) file_get_contents($dir.'/sitemap.xml'));

            foreach ($cases as $case) {
                $html = $this->invoke(
                    'moviePageHtml',
                    $case['movie'],
                    $case['isJapan'],
                    sys_get_temp_dir(),
                );
                $movieLd = $this->movieJsonLd($html);
                $loc = 'https://mubiran.com/movies/'.$case['id'].'/';
                if (isset($movieLd['dateModified'])) {
                    $this->assertSame($movieLd['dateModified'], $entries[$loc]);
                    $this->assertSame($movieLd['dateModified'], $this->invoke('movieAsOfTimestamp', $case['movie']));
                } else {
                    $this->assertNull($entries[$loc]);
                }
            }
        } finally {
            @unlink($dir.'/sitemap.xml');
            @rmdir($dir);
        }
    }

    public function test_world_movie_meta_leads_with_world_gross_not_yen_conversion(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = $this->detailDir('tmdb-299534', [
            'title' => 'アベンジャーズ／エンドゲーム',
            'original_title' => 'Avengers: Endgame',
            'release_date' => '2019-04-24',
            'overview' => '破格のメガヒットによって映画史を塗り替え続ける「アベンジャーズ」シリーズが、この春ついに完結。',
        ]);

        try {
            $html = $this->invoke('moviePageHtml', [
                'id' => 'tmdb-299534',
                'title' => 'アベンジャーズ／エンドゲーム',
                'originalTitle' => 'Avengers: Endgame',
                'revenue' => '29.42億ドル',
                'revenueYen' => '4,413.3億円',
                'boxOffice' => 2_942_000_000,
                'rank' => 1,
                'releaseDate' => '2019-04-24',
                'releaseDatePrecision' => 'day',
                'updatedAt' => '2026-10-29T03:00:00+09:00',
                'pageHistory' => [
                    'grossIncreasedAt' => '2026-10-28T03:00:00+09:00',
                ],
                'posterUrl' => '',
                'genres' => [],
            ], false, $dir, [
                'tmdb-299534' => ['revenue' => '29.42億ドル', 'rank' => 1],
                'jp-tmdb-299534' => ['revenue' => '61.3億円', 'rank' => 150],
            ]);
        } finally {
            $this->removeDetailDir($dir);
        }

        $meta = $this->metaDescription($html);
        $this->assertSame($meta, $this->ogDescription($html));
        $this->assertSame($meta, $this->twitterDescription($html));
        $this->assertStringStartsWith(
            'アベンジャーズ／エンドゲームの世界興収は29.42億ドル（世界ランキング第1位）。2019年4月24日公開。2026年10月28日時点。日本興収は61.3億円（日本ランキング第150位）。',
            $meta,
        );
        $this->assertStringNotContainsString('4,413.3億円', $meta);
        $this->assertStringNotContainsString('日本換算', $meta);
        $this->assertLessThanOrEqual(120, mb_strlen($meta));

        $body = $this->bodyHtml($html);
        $this->assertStringContainsString('<dt>日本換算</dt><dd>4,413.3億円</dd>', $body);
        $this->assertStringContainsString('<dt>興行収入</dt><dd>29.42億ドル</dd>', $body);
        $this->assertStringNotContainsString('name="description"', $body);

        $movieLd = $this->movieJsonLd($html);
        $this->assertSame('Movie', $movieLd['@type']);
        $this->assertSame('2019-04-24', $movieLd['datePublished']);
        $this->assertSame('2026-10-28T03:00:00+09:00', $movieLd['dateModified']);
        $this->assertSame([
            '@type' => 'MonetaryAmount',
            'currency' => 'USD',
            'value' => 2_942_000_000,
        ], $movieLd['boxOffice']);
        $this->assertSame('https://mubiran.com/movies/jp-tmdb-299534/', $movieLd['sameAs']);
        $this->assertSame(
            '破格のメガヒットによって映画史を塗り替え続ける「アベンジャーズ」シリーズが、この春ついに完結。',
            $movieLd['description'],
        );
        $this->assertContains('BreadcrumbList', array_column($this->jsonLd($html)['@graph'], '@type'));
    }

    public function test_japan_movie_meta_leads_with_japan_gross_and_omits_missing_fields(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = $this->detailDir('jp-tmdb-280', [
            'title' => 'ターミネーター2',
            'original_title' => 'Terminator 2: Judgment Day',
            'release_date' => '1991-08-24',
        ]);

        try {
            $html = $this->invoke('moviePageHtml', [
                'id' => 'jp-tmdb-280',
                'title' => 'ターミネーター2',
                'originalTitle' => 'Terminator 2: Judgment Day',
                'revenue' => '87.9億円',
                'revenueYen' => null,
                'boxOffice' => 8_790_000_000,
                'rank' => 81,
                'releaseDate' => '1991-08-24',
                'releaseDatePrecision' => 'day',
                'posterUrl' => '',
                'genres' => [],
            ], true, $dir);
        } finally {
            $this->removeDetailDir($dir);
        }

        $meta = $this->metaDescription($html);
        $this->assertSame(
            'ターミネーター2の日本興収は87.9億円（日本ランキング第81位）。1991年8月24日公開。',
            $meta,
        );
        $this->assertStringNotContainsString('世界興収', $meta);
        $this->assertStringNotContainsString('原題:', $meta);

        $movieLd = $this->movieJsonLd($html);
        $this->assertSame([
            '@type' => 'MonetaryAmount',
            'currency' => 'JPY',
            'value' => 8_790_000_000,
        ], $movieLd['boxOffice']);
        $this->assertSame('1991-08-24', $movieLd['datePublished']);
        $this->assertArrayNotHasKey('dateModified', $movieLd);
        $this->assertArrayNotHasKey('sameAs', $movieLd);
        $this->assertStringNotContainsString('日本換算', $this->bodyHtml($html));
    }

    public function test_thin_movie_page_keeps_a_short_factual_description(): void
    {
        config(['app.url' => 'https://mubiran.com']);
        $dir = $this->detailDir('jp-tmdb-1545621', [
            'title' => '名探偵コナン ハイウェイの堕天使',
        ]);

        try {
            $html = $this->invoke('moviePageHtml', [
                'id' => 'jp-tmdb-1545621',
                'title' => '名探偵コナン ハイウェイの堕天使',
                'revenue' => '137.0億円',
                'revenueYen' => null,
                'boxOffice' => 13_700_000_000,
                'rank' => 28,
                'updatedAt' => '2026-10-14T21:00:00+09:00',
                'posterUrl' => '',
                'genres' => [],
            ], true, $dir);
        } finally {
            $this->removeDetailDir($dir);
        }

        $this->assertSame(
            '名探偵コナン ハイウェイの堕天使の日本興収は137.0億円（日本ランキング第28位）。2026年10月14日時点。',
            $this->metaDescription($html),
        );
        $this->assertSame('JPY', $this->movieJsonLd($html)['boxOffice']['currency']);
        $this->assertSame('2026-10-14T21:00:00+09:00', $this->movieJsonLd($html)['dateModified']);
        $this->assertArrayNotHasKey('datePublished', $this->movieJsonLd($html));
    }

    public function test_movie_meta_appends_synopsis_only_within_the_character_budget(): void
    {
        $leadMovie = [
            'id' => 'tmdb-1',
            'title' => '短い題',
            'revenue' => '1.00億ドル',
            'rank' => 2,
            'releaseDate' => '2024-01-01',
            'updatedAt' => '2026-01-02T00:00:00+09:00',
        ];
        $longOverview = str_repeat('あらすじ', 80);
        $description = $this->invoke(
            'movieMetaDescription',
            $leadMovie,
            false,
            '短い題',
            '2024-01-01',
            $longOverview,
            [],
        );

        $this->assertSame(120, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
        $this->assertStringStartsWith('短い題の世界興収は1.00億ドル（世界ランキング第2位）。2024年1月1日公開。2026年1月2日時点。', $description);
        $this->assertStringNotContainsString('日本換算', $description);
        $this->assertStringNotContainsString('日本興収', $description);

        $short = $this->invoke(
            'movieMetaDescription',
            $leadMovie,
            false,
            '短い題',
            '2024-01-01',
            '短いあらすじ。',
            [],
        );
        $this->assertStringEndsWith('短いあらすじ。', $short);
        $this->assertSame($short, $this->invoke(
            'movieMetaDescription',
            $leadMovie,
            false,
            '短い題',
            '2024-01-01',
            '短いあらすじ。',
            ['jp-tmdb-1' => true],
        ));
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

    /**
     * @return array<string, string|null>
     */
    private function sitemapEntries(string $xml): array
    {
        preg_match_all(
            '#<url>\s*<loc>(.*?)</loc>(?:\s*<lastmod>(.*?)</lastmod>)?\s*<changefreq>.*?</changefreq>\s*<priority>.*?</priority>\s*</url>#s',
            $xml,
            $matches,
            PREG_SET_ORDER,
        );
        $this->assertNotSame([], $matches);

        $entries = [];
        foreach ($matches as $match) {
            $lastmod = $match[2] ?? '';
            $entries[html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = $lastmod === '' ? null : $lastmod;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function detailDir(string $movieId, array $detail): string
    {
        $dir = sys_get_temp_dir().'/mubiran-seo-'.bin2hex(random_bytes(4));
        mkdir($dir.'/data/details', 0777, true);
        file_put_contents(
            $dir.'/data/details/'.$movieId.'.json',
            json_encode($detail, JSON_UNESCAPED_UNICODE),
        );

        return $dir;
    }

    private function removeDetailDir(string $dir): void
    {
        @unlink($dir.'/data/details/'.basename(str_replace('\\', '/', $dir)));
        foreach (glob($dir.'/data/details/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir.'/data/details');
        @rmdir($dir.'/data');
        @rmdir($dir);
    }

    private function ensureViteManifest(): void
    {
        $dir = public_path('build');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir.'/manifest.json';
        if (! is_file($path)) {
            file_put_contents($path, json_encode([
                'resources/js/static-site.js' => [
                    'file' => 'assets/static-site-test.js',
                    'css' => ['assets/static-site-test.css'],
                ],
            ]));
        }
    }

    private function metaDescription(string $html): string
    {
        $this->assertSame(1, preg_match('/<meta name="description" content="([^"]*)">/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function ogDescription(string $html): string
    {
        $this->assertSame(1, preg_match('/<meta property="og:description" content="([^"]*)">/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function twitterDescription(string $html): string
    {
        $this->assertSame(1, preg_match('/<meta name="twitter:description" content="([^"]*)">/', $html, $matches));

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function bodyHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<body\b[^>]*>(.*)<\/body>/s', $html, $matches));

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches));
        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function movieJsonLd(string $html): array
    {
        foreach ($this->jsonLd($html)['@graph'] ?? [] as $node) {
            if (is_array($node) && ($node['@type'] ?? null) === 'Movie') {
                return $node;
            }
        }

        $this->fail('Movie JSON-LD was not found');
    }
}
