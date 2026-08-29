<?php

namespace Tests\Unit\Tmdb;

use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TmdbClientTest extends TestCase
{
    public function test_is_error_payload_detects_tmdb_backend_errors(): void
    {
        $payload = [
            'status_code' => 43,
            'status_message' => "Couldn't connect to the backend server.",
            'success' => false,
        ];

        $this->assertTrue(TmdbClient::isErrorPayload($payload));
        $this->assertFalse(TmdbClient::isValidMovieResponse($payload));
    }

    public function test_is_valid_movie_response_requires_revenue_and_budget(): void
    {
        $valid = [
            'id' => 1,
            'title' => 'Avatar',
            'revenue' => 2923706026,
            'budget' => 237000000,
        ];

        $this->assertTrue(TmdbClient::isValidMovieResponse($valid));
        $this->assertFalse(TmdbClient::isValidMovieResponse([
            'id' => 1,
            'title' => 'Avatar',
        ]));
    }

    public function test_get_movie_retries_after_error_payload(): void
    {
        Http::fake([
            'api.themoviedb.org/3/movie/135397*' => Http::sequence()
                ->push([
                    'status_code' => 43,
                    'status_message' => "Couldn't connect to the backend server.",
                    'success' => false,
                ], 200)
                ->push([
                    'id' => 135397,
                    'title' => 'ジュラシック・ワールド',
                    'revenue' => 1671537444,
                    'budget' => 150000000,
                ], 200),
        ]);

        $client = new TmdbClient('test-api-key', 2);
        $movie = $client->getMovie(135397);

        $this->assertNotNull($movie);
        $this->assertSame(135397, $movie['id']);
        $this->assertSame(1671537444, $movie['revenue']);
    }

    public function test_discover_movies_retries_on_server_error(): void
    {
        Http::fake([
            'api.themoviedb.org/3/discover/movie*' => Http::sequence()
                ->push('Bad Gateway', 502)
                ->push([
                    'page' => 1,
                    'results' => [
                        ['id' => 19995, 'title' => 'Avatar', 'revenue' => 2923706026],
                    ],
                ], 200),
        ]);

        $client = new TmdbClient('test-api-key', 2);
        $results = $client->discoverMovies(1);

        $this->assertNotNull($results);
        $this->assertCount(1, $results);
        $this->assertSame(19995, $results[0]['id']);
    }
}
