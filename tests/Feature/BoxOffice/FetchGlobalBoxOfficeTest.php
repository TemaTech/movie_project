<?php

namespace Tests\Feature\BoxOffice;

use App\Console\Commands\FetchGlobalBoxOffice;
use App\Services\BoxOffice\GlobalRankingAssembler;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\Series\BrandCatalog;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class FetchGlobalBoxOfficeTest extends TestCase
{
    private string $historyDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->historyDir = sys_get_temp_dir().'/global-rank-'.bin2hex(random_bytes(4));
        mkdir($this->historyDir.'/observations/global', 0777, true);
        mkdir($this->historyDir.'/observations/japan', 0777, true);
        file_put_contents($this->historyDir.'/registry.json', "{\"movies\":{},\"aliases\":{}}\n");
        config(['box_office.history_path' => $this->historyDir]);
        config(['services.tmdb.api_key' => 'test-api-key']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->historyDir);
        parent::tearDown();
    }

    public function test_previous_ranking_film_is_restored_when_discover_omits_it(): void
    {
        $this->rememberGlobalFilm(99861, 'アベンジャーズ／エイジ・オブ・ウルトロン', 1_405_403_694, '2015-04-22');
        $this->fakeMovie(99861, 'アベンジャーズ／エイジ・オブ・ウルトロン', 1_405_403_694, 20_000, '2015-04-22');

        $discover = [
            $this->rankingRow(10, '高い作品', 1_000_000_000),
            $this->rankingRow(11, '中くらい', 900_000_000),
            $this->rankingRow(12, '低い作品', 800_000_000),
        ];

        $command = $this->command();
        $merged = $this->invoke($command, 'appendPreviousRankingMovies', $discover, []);
        $ranking = GlobalRankingAssembler::finalize($merged, 3);

        $this->assertSame(99861, $ranking[0]['tmdb_id']);
        $this->assertSame(1, $ranking[0]['rank']);
        $this->assertSame(1_405_403_694, $ranking[0]['box_office']);
        $this->assertSame([99861, 10, 11], array_column($ranking, 'tmdb_id'));
        $this->assertNotContains(12, array_column($ranking, 'tmdb_id'));
    }

    public function test_series_extra_above_the_cutoff_is_promoted_into_the_ranking(): void
    {
        $this->fakeMovie(1858, 'トランスフォーマー', 709_709_780, 9_000, '2007-06-27');

        $discover = [
            $this->rankingRow(10, '高い作品', 1_000_000_000),
            $this->rankingRow(11, '中くらい', 900_000_000),
            $this->rankingRow(12, '低い作品', 500_000_000),
        ];
        $extras = [[
            'tmdb_id' => 1858,
            'title' => 'トランスフォーマー',
            'box_office' => 709_709_780,
            'release_date' => '2007-06-27',
        ]];

        $command = $this->command();
        $merged = $this->invoke($command, 'promoteHighGrossingExtras', $discover, $extras, []);
        $ranking = GlobalRankingAssembler::finalize($merged, 3);

        $this->assertSame(1858, $ranking[2]['tmdb_id']);
        $this->assertSame(3, $ranking[2]['rank']);
        $this->assertNotContains(12, array_column($ranking, 'tmdb_id'));
    }

    public function test_low_vote_and_dummy_revenue_extras_are_not_promoted(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/movie/1?')) {
                return Http::response($this->tmdbPayload(1, '投票不足', 900_000_000, 12, '2020-01-01'));
            }
            if (str_contains($url, '/movie/2?')) {
                return Http::response($this->tmdbPayload(2, 'ダミー興収', 1_000_000_000, 5_000, '2020-01-01'));
            }

            return Http::response('', 404);
        });

        $discover = [
            $this->rankingRow(10, '高い作品', 1_000_000_000),
            $this->rankingRow(11, '中くらい', 900_000_000),
            $this->rankingRow(12, '低い作品', 800_000_000),
        ];
        $extras = [
            ['tmdb_id' => 1, 'title' => '投票不足', 'box_office' => 900_000_000, 'release_date' => '2020-01-01'],
            ['tmdb_id' => 2, 'title' => 'ダミー興収', 'box_office' => 1_000_000_000, 'release_date' => '2020-01-01'],
        ];

        $command = $this->command();
        $merged = $this->invoke($command, 'promoteHighGrossingExtras', $discover, $extras, []);

        $this->assertSame([10, 11, 12], array_column($merged, 'tmdb_id'));
    }

    public function test_previous_ranking_dummy_revenue_is_not_restored(): void
    {
        $this->rememberGlobalFilm(99, 'ダミー興収', 1_000_000_000, '2020-01-01');
        $this->fakeMovie(99, 'ダミー興収', 1_000_000_000, 5_000, '2020-01-01');

        $discover = [
            $this->rankingRow(10, '高い作品', 1_000_000_000),
            $this->rankingRow(11, '中くらい', 900_000_000),
            $this->rankingRow(12, '低い作品', 800_000_000),
        ];

        $command = $this->command();
        $merged = $this->invoke($command, 'appendPreviousRankingMovies', $discover, []);

        $this->assertSame([10, 11, 12], array_column($merged, 'tmdb_id'));
        $this->assertNotContains(99, array_column($merged, 'tmdb_id'));
    }

    public function test_restored_world_ranking_puts_mcu_back_to_25_films(): void
    {
        $path = base_path('tests/fixtures/global-ranking-build-393.json');
        $discover = json_decode((string) file_get_contents($path), true);
        $movies = array_map(fn (array $row): array => [
            'tmdb_id' => $row['tmdbId'],
            'movie_id' => $row['id'],
            'title' => $row['title'],
            'box_office' => $row['boxOffice'],
        ], $discover);
        $movies[] = [
            'tmdb_id' => 99861,
            'movie_id' => 'tmdb-99861',
            'title' => 'アベンジャーズ／エイジ・オブ・ウルトロン',
            'box_office' => 1_405_403_694,
        ];
        $movies[] = [
            'tmdb_id' => 1858,
            'movie_id' => 'tmdb-1858',
            'title' => 'トランスフォーマー',
            'box_office' => 709_709_780,
        ];

        $ranking = GlobalRankingAssembler::finalize($movies, 200);
        $catalog = BrandCatalog::world(array_map(fn (array $row): array => [
            'id' => $row['movie_id'],
            'tmdbId' => $row['tmdb_id'],
            'title' => $row['title'],
            'boxOffice' => $row['box_office'],
            'inRanking' => true,
            'rank' => $row['rank'],
        ], $ranking));
        $mcu = collect($catalog['series'])->firstWhere('slug', 'mcu');

        $this->assertIsArray($mcu);
        $this->assertSame(25, $mcu['filmCount']);
        $this->assertSame(29_763_289_672, $mcu['totalUsd']);
        $this->assertContains('tmdb-99861', array_column($mcu['films'], 'id'));
    }

    public function test_all_films_brand_adds_released_films_missing_from_ranking_and_series(): void
    {
        $cacheDir = storage_path('app/tmdb-series/movies');
        $ids = [990001, 990002, 990003, 990004, 990005];
        $this->forgetSeriesCache($ids);
        $calls = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
            $url = $request->url();
            if (! preg_match('~/movie/(\d+)\?~', $url, $matches)) {
                return Http::response('', 404);
            }
            $id = (int) $matches[1];
            $calls[] = $id;
            $payload = match ($id) {
                990002 => ['belongs_to_collection' => ['id' => 5, 'name' => '別のコレクション']]
                    + $this->tmdbPayload(990002, 'ランキング外の作品', 382_000_000, 3_000, '2025-04-30'),
                990003 => $this->tmdbPayload(990003, '公開前の作品', 0, 0, '2099-12-18'),
                990005 => $this->tmdbPayload(990005, '興収不明の作品', 0, 800, '2021-11-03'),
                default => null,
            };

            return $payload === null ? Http::response('', 404) : Http::response($payload);
        });

        $definitions = [[
            'slug' => 'test-universe',
            'name' => 'テスト',
            'allFilmsRegions' => ['global'],
            'tmdbIds' => $ids,
            'excludeTmdbIds' => [990004],
        ], [
            'slug' => 'ranking-only',
            'name' => '歴代だけ',
            'tmdbIds' => [990006],
        ]];
        $ranking = [$this->rankingRow(990001, '歴代に入っている作品', 900_000_000)];
        $seriesExtras = [];

        try {
            $command = $this->command();
            $extras = $this->invoke($command, 'appendBrandExtras', $ranking, $seriesExtras, $definitions);
            $cachedUnreleased = is_file($cacheDir.'/990003.json');
            $cachedReleased = is_file($cacheDir.'/990002.json');
        } finally {
            $this->forgetSeriesCache($ids);
        }

        $this->assertSame([990002, 990005], array_column($extras, 'tmdb_id'));
        $added = $extras[0];
        $this->assertSame('tmdb-990002', $added['movie_id']);
        $this->assertSame(0, $added['in_ranking']);
        $this->assertNull($added['rank']);
        $this->assertSame(382_000_000, $added['box_office']);
        $this->assertSame('2025-04-30', $added['release_date']);
        $this->assertNull($added['tmdb_collection_id'], 'ブランドだけで足した作品はシリーズページを作らない');
        $this->assertNull($added['tmdb_collection_name']);
        $this->assertSame(0, $extras[1]['box_office'], '興収が無い作品も 0（不明）で残す');
        $this->assertTrue($cachedReleased);
        $this->assertFalse($cachedUnreleased, '公開前の詳細はキャッシュに残さない');
        $this->assertNotContains(990001, $calls, '歴代に入っている作品は取り直さない');
        $this->assertNotContains(990004, $calls, '除外した作品は取らない');
        $this->assertNotContains(990006, $calls, '全作品指定のないブランドは取らない');
    }

    /**
     * @param  list<int>  $ids
     */
    private function forgetSeriesCache(array $ids): void
    {
        foreach ($ids as $id) {
            $path = storage_path('app/tmdb-series/movies/'.$id.'.json');
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function fakeMovie(int $tmdbId, string $title, int $revenue, int $voteCount, string $releaseDate): void
    {
        Http::fake([
            'api.themoviedb.org/3/movie/'.$tmdbId.'*' => Http::response(
                $this->tmdbPayload($tmdbId, $title, $revenue, $voteCount, $releaseDate)
            ),
            'image.tmdb.org/*' => Http::response('', 404),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tmdbPayload(int $tmdbId, string $title, int $revenue, int $voteCount, string $releaseDate): array
    {
        return [
            'id' => $tmdbId,
            'title' => $title,
            'original_title' => $title,
            'revenue' => $revenue,
            'budget' => 100_000_000,
            'vote_count' => $voteCount,
            'release_date' => $releaseDate,
            'poster_path' => null,
            'genres' => [['name' => 'アクション']],
            'belongs_to_collection' => null,
            'production_countries' => [['iso_3166_1' => 'US']],
            'release_dates' => ['results' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rankingRow(int $tmdbId, string $title, int $boxOffice): array
    {
        return [
            'movie_id' => 'tmdb-'.$tmdbId,
            'tmdb_id' => $tmdbId,
            'title' => $title,
            'box_office' => $boxOffice,
            'rank' => 0,
            'in_ranking' => 1,
        ];
    }

    private function rememberGlobalFilm(int $tmdbId, string $title, int $boxOffice, string $releaseDate): void
    {
        $history = HistoryRecorder::fromBasePath($this->historyDir);
        $history->resolve([
            'region' => 'global',
            'title' => $title,
            'tmdbId' => $tmdbId,
            'releaseYear' => (int) substr($releaseDate, 0, 4),
            'releaseDate' => $releaseDate,
        ]);
        $history->save();
        file_put_contents(
            $this->historyDir.'/observations/global/2026-08.ndjson',
            json_encode([
                'key' => 'tmdb-'.$tmdbId,
                'observedAt' => '2026-08-15T03:40:40+09:00',
                'boxOffice' => $boxOffice,
                'isActive' => false,
            ], JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    private function command(): FetchGlobalBoxOffice
    {
        $command = $this->app->make(FetchGlobalBoxOffice::class);
        $input = new ArrayInput([]);
        $command->setLaravel($this->app);
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new NullOutput));
        $tmdb = new ReflectionProperty(FetchGlobalBoxOffice::class, 'tmdb');
        $tmdb->setAccessible(true);
        $tmdb->setValue($command, new TmdbClient('test-api-key', 1));
        $history = new ReflectionProperty(FetchGlobalBoxOffice::class, 'history');
        $history->setAccessible(true);
        $history->setValue($command, HistoryRecorder::fromBasePath($this->historyDir));
        $observations = new ReflectionProperty(FetchGlobalBoxOffice::class, 'globalObservations');
        $observations->setAccessible(true);
        $observations->setValue($command, null);

        return $command;
    }

    private function invoke(FetchGlobalBoxOffice $command, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(FetchGlobalBoxOffice::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($command, ...$arguments);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
