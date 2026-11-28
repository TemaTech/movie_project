<?php

namespace Tests\Feature\Series;

use App\Console\Commands\ExportStaticSite;
use App\Services\Series\BrandCatalog;
use App\Services\Series\PopularSeriesLinks;
use App\Services\Series\SeriesCatalog;
use App\Services\Site\InternalLinkChecker;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class PopularSeriesExportTest extends TestCase
{
    private ExportStaticSite $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureViteManifest();
        config(['app.url' => 'https://mubiran.com']);
        $this->command = $this->app->make(ExportStaticSite::class);
    }

    public function test_top_and_japan_rankings_link_to_exported_popular_series_pages(): void
    {
        $japanSeries = SeriesCatalog::japan([]);
        $worldSeries = SeriesCatalog::world($this->worldFixture());
        $japanBrands = BrandCatalog::japan($this->japanBrandFixture());
        $worldBrands = BrandCatalog::world([]);
        $popular = [
            'global' => PopularSeriesLinks::pick($worldSeries, $worldBrands),
            'japan' => PopularSeriesLinks::pick($japanSeries, $japanBrands),
        ];
        $this->setPopular($popular);

        $this->assertSame(['ハリー・ポッター シリーズ', 'スター・ウォーズ シリーズ'], array_column($popular['global'], 'name'));
        $this->assertSame('名探偵コナン', $popular['japan'][0]['name']);
        $this->assertContains('スタジオジブリ映画', array_column($popular['japan'], 'name'));

        $dir = sys_get_temp_dir().'/mubiran-popular-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->invoke('exportSeriesPages', $dir, $japanSeries, $worldSeries, $japanBrands);
            $this->invoke('exportSeriesPages', $dir, $worldSeries, $japanSeries, $worldBrands);
            $this->invoke('exportBrandPages', $dir, $japanBrands, $worldBrands);
            $this->invoke('exportBrandPages', $dir, $worldBrands, $japanBrands);
            $top = $this->invoke('indexHtml', '/', $this->rankingMovies(), null);
            $japan = $this->invoke('indexHtml', '/japan/', $this->rankingMovies(), null);
            mkdir($dir.'/japan');
            file_put_contents($dir.'/index.html', $top);
            file_put_contents($dir.'/japan/index.html', $japan);

            foreach (['global' => $top, 'japan' => $japan] as $region => $html) {
                $this->assertStringContainsString('<h2 class="popular-series-title">人気のシリーズ・ブランド</h2>', $html);
                foreach ($popular[$region] as $link) {
                    $this->assertStringContainsString(
                        '<a href="'.htmlspecialchars($link['href'], ENT_QUOTES).'">'.htmlspecialchars($link['name'], ENT_QUOTES).'</a>',
                        $html,
                        $region.': '.$link['href']
                    );
                    $this->assertTrue(InternalLinkChecker::targetExists($dir, $link['href']), $link['href'].' が書き出されていない');
                }
            }
            $this->assertStringContainsString('（<a href="/series/global/">すべてのシリーズ</a>）', $top);
            $this->assertStringContainsString('（<a href="/series/">すべてのシリーズ</a>）', $japan);
            $this->assertStringNotContainsString('<a href="/series/brand/ghibli/"', $top, 'トップには世界向けのリンクだけ');
            $this->assertStringNotContainsString('<a href="/series/global/', $japan, '/japan/ には日本向けのリンクだけ');
            $this->assertStringContainsString('<script type="application/json" id="popular-series">', $top);

            // トップと /japan/ から出ているシリーズ・ブランドへのリンクに欠落が無いこと
            $missing = array_filter(
                InternalLinkChecker::scan($dir)['missing'],
                fn (array $row): bool => str_starts_with((string) $row['href'], '/series/')
                    && in_array(ltrim(str_replace('\\', '/', (string) $row['from']), '/'), ['index.html', 'japan/index.html'], true)
            );
            $this->assertSame([], array_values($missing));
        } finally {
            $this->removeDirectory($dir);
        }
    }

    public function test_embedded_json_lists_both_regions_without_breaking_out_of_the_script(): void
    {
        $this->setPopular([
            'global' => [['name' => 'A</script> シリーズ', 'href' => '/series/global/a/', 'kind' => 'series']],
            'japan' => [['name' => '名探偵コナン', 'href' => '/series/名探偵コナン/', 'kind' => 'series']],
        ]);

        $script = $this->invoke('popularSeriesJsonScript');
        $this->assertStringNotContainsString('A</script>', $script);
        $json = json_decode(substr($script, strpos($script, '>') + 1, -strlen('</script>')), true);
        $this->assertSame([['name' => 'A</script> シリーズ', 'href' => '/series/global/a/']], $json['global']);
        $this->assertSame('/series/名探偵コナン/', $json['japan'][0]['href']);
    }

    public function test_section_is_omitted_when_nothing_is_picked(): void
    {
        $html = $this->invoke('rankingStaticHtml', $this->rankingMovies(), false, null);

        $this->assertStringNotContainsString('popular-series', $html);
        $this->assertSame('', $this->invoke('popularSeriesJsonScript'));
        $this->assertStringContainsString('href="/series/"', $html);
    }

    /**
     * @param  array<string, list<array<string, string>>>  $links
     */
    private function setPopular(array $links): void
    {
        $property = new ReflectionProperty(ExportStaticSite::class, 'popularSeriesLinks');
        $property->setAccessible(true);
        $property->setValue($this->command, $links);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(ExportStaticSite::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->command, ...$arguments);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rankingMovies(): array
    {
        return [['id' => 'movie-1', 'slug' => 'movie-1', 'rank' => 1, 'title' => '作品1', 'revenue' => '1億円']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function worldFixture(): array
    {
        return [
            $this->film('tmdb-671', 671, 'ハリー・ポッターと賢者の石', 974_755_371, 12, 1241, 'ハリー・ポッター コレクション'),
            $this->film('tmdb-672', 672, 'ハリー・ポッターと秘密の部屋', 879_000_000, 30, 1241, 'ハリー・ポッター コレクション'),
            $this->film('tmdb-11', 11, 'スター・ウォーズ', 775_000_000, 60, 10, 'スター・ウォーズ シリーズ'),
            $this->film('tmdb-1891', 1891, '帝国の逆襲', 547_000_000, 120, 10, 'スター・ウォーズ シリーズ'),
            $this->film('tmdb-8966', 8966, 'トワイライト～初恋～', 3_000_000_000, 5, 33514, 'Twilight Collection'),
            $this->film('tmdb-8967', 8967, 'ニュームーン', 2_000_000_000, 6, 33514, 'Twilight Collection'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function japanBrandFixture(): array
    {
        return [
            $this->film('jp-tmdb-129', 129, '千と千尋の神隠し', 31_680_000_000, 3),
            $this->film('jp-tmdb-128', 128, 'もののけ姫', 20_180_000_000, 12),
            $this->film('jp-tmdb-4935', 4935, 'ハウルの動く城', 19_600_000_000, 13),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function film(string $id, int $tmdbId, string $title, int $boxOffice, int $rank, ?int $collectionId = null, ?string $collectionName = null): array
    {
        return array_filter([
            'id' => $id,
            'slug' => $id,
            'tmdbId' => $tmdbId,
            'title' => $title,
            'originalTitle' => $title,
            'rank' => $rank,
            'inRanking' => true,
            'boxOffice' => $boxOffice,
            'revenue' => 'x',
            'releaseYear' => 2001,
            'posterUrl' => '',
            'genres' => [],
            'collectionId' => $collectionId,
            'collectionName' => $collectionName,
        ], fn (mixed $value): bool => $value !== null);
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

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
