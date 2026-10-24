<?php

namespace Tests\Unit\Tmdb;

use App\Console\Commands\ExportStaticSite;
use App\Models\GlobalMovie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class MovieDetailExportTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['test-credit-lang-en', 'test-credit-lang-ja'] as $movieId) {
            File::delete(storage_path("app/static-details/{$movieId}.json"));
        }
        File::deleteDirectory(storage_path('framework/testing/movie-details'));

        parent::tearDown();
    }

    public function test_english_credit_cache_is_refetched_in_japanese(): void
    {
        config(['services.tmdb.api_key' => 'test-api-key']);
        $movieId = 'test-credit-lang-en';
        $this->writeCache($movieId, [
            'title' => 'アベンジャーズ／エンドゲーム',
            'credits' => [
                'cast' => [
                    ['name' => 'Robert Downey Jr.', 'profile_path' => '/en.jpg'],
                ],
                'crew' => [
                    ['job' => 'Director', 'name' => 'Anthony Russo'],
                ],
            ],
        ]);

        Http::fake([
            'api.themoviedb.org/3/movie/299534*' => Http::response([
                'id' => 299534,
                'title' => 'アベンジャーズ／エンドゲーム',
                'original_title' => 'Avengers: Endgame',
                'revenue' => 2938567784,
                'budget' => 356000000,
                'credits' => [
                    'cast' => [
                        ['name' => 'ロバート・ダウニー・Jr.', 'profile_path' => '/ja.jpg'],
                    ],
                    'crew' => [
                        ['job' => 'Director', 'name' => 'アンソニー・ルッソ'],
                        ['job' => 'Writer', 'name' => 'クリストファー・マルクス'],
                    ],
                ],
            ], 200),
        ]);

        $detailsDir = storage_path('framework/testing/movie-details');
        $exported = $this->exportDetail($movieId, $this->movie($movieId), $detailsDir);

        $this->assertTrue($exported);
        $detail = json_decode(File::get("{$detailsDir}/{$movieId}.json"), true);
        $this->assertSame('ja-JP', $detail['names_language']);
        $this->assertSame('ロバート・ダウニー・Jr.', $detail['credits']['cast'][0]['name']);
        $this->assertSame('アンソニー・ルッソ', $detail['credits']['crew'][0]['name']);
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/movie/299534')
                && ! str_contains($request->url(), '/credits')
                && ($query['language'] ?? null) === 'ja-JP'
                && ($query['append_to_response'] ?? null) === 'credits';
        });
    }

    public function test_japanese_credit_cache_is_reused(): void
    {
        config(['services.tmdb.api_key' => 'test-api-key']);
        Http::preventStrayRequests();
        $movieId = 'test-credit-lang-ja';
        $this->writeCache($movieId, [
            'title' => 'アベンジャーズ／エンドゲーム',
            'credits' => [
                'cast' => [
                    ['name' => 'ロバート・ダウニー・Jr.', 'profile_path' => '/ja.jpg'],
                ],
                'crew' => [
                    ['job' => 'Director', 'name' => 'アンソニー・ルッソ'],
                ],
            ],
        ]);

        $detailsDir = storage_path('framework/testing/movie-details');
        $exported = $this->exportDetail($movieId, $this->movie($movieId), $detailsDir);

        $this->assertTrue($exported);
        $detail = json_decode(File::get("{$detailsDir}/{$movieId}.json"), true);
        $this->assertSame('ロバート・ダウニー・Jr.', $detail['credits']['cast'][0]['name']);
        $this->assertSame('アンソニー・ルッソ', $detail['credits']['crew'][0]['name']);
    }

    private function movie(string $movieId): GlobalMovie
    {
        $movie = new GlobalMovie;
        $movie->movie_id = $movieId;
        $movie->tmdb_id = 299534;
        $movie->title = 'アベンジャーズ／エンドゲーム';
        $movie->poster_path = '/poster.jpg';

        return $movie;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function writeCache(string $movieId, array $detail): void
    {
        $path = storage_path("app/static-details/{$movieId}.json");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($detail, JSON_UNESCAPED_UNICODE));
    }

    private function exportDetail(string $movieId, GlobalMovie $movie, string $detailsDir): bool
    {
        $method = new ReflectionMethod(ExportStaticSite::class, 'exportMovieDetail');
        $method->setAccessible(true);

        return $method->invoke(
            $this->app->make(ExportStaticSite::class),
            $movieId,
            $movie,
            $detailsDir,
            false,
        );
    }
}
