<?php

namespace App\Services\Tmdb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TmdbClient
{
    private const BASE_URL = 'https://api.themoviedb.org/3';

    /** @var list<int> */
    private const RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    private const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('services.tmdb.api_key'));
    }

    /**
     * @return list<array<string, mixed>>|null null when the request failed after retries
     */
    public function discoverMovies(int $page): ?array
    {
        $data = $this->request('get', '/discover/movie', [
            'sort_by' => 'revenue.desc',
            'language' => 'ja',
            'page' => $page,
            'include_adult' => false,
            'include_video' => false,
        ]);

        if ($data === null) {
            return null;
        }

        return $data['results'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMovie(int $movieId, string $language = 'ja'): ?array
    {
        $data = $this->request('get', "/movie/{$movieId}", [
            'language' => $language,
        ]);

        if ($data === null || ! self::isValidMovieResponse($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMovieCredits(int $movieId): ?array
    {
        $data = $this->request('get', "/movie/{$movieId}/credits");

        if ($data === null || ! isset($data['cast'], $data['crew'])) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function searchMovies(string $query, ?int $year = null, string $language = 'ja-JP'): array
    {
        $params = [
            'query' => $query,
            'language' => $language,
        ];

        if ($year) {
            $params['primary_release_year'] = $year;
        }

        $data = $this->request('get', '/search/movie', $params);

        return $data['results'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function isValidMovieResponse(array $data): bool
    {
        return isset($data['id'], $data['title'])
            && array_key_exists('revenue', $data)
            && array_key_exists('budget', $data);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    public static function isErrorPayload(array $json): bool
    {
        return isset($json['status_code'], $json['status_message'])
            && ! isset($json['id']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $query = []): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $query['api_key'] = $this->apiKey;
        $url = self::BASE_URL.$path;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->{$method}($url, $query);

                $lastResponse = $response;

                if ($response->successful()) {
                    $json = $response->json();
                    if (! is_array($json)) {
                        $this->sleepBeforeRetry($attempt);

                        continue;
                    }

                    if (self::isErrorPayload($json)) {
                        Log::warning('TMDb API returned error payload', [
                            'path' => $path,
                            'status_code' => $json['status_code'] ?? null,
                            'status_message' => $json['status_message'] ?? null,
                            'attempt' => $attempt,
                        ]);
                        $this->sleepBeforeRetry($attempt, (int) ($json['status_code'] ?? 0));

                        continue;
                    }

                    usleep(100000);

                    return $json;
                }

                if (in_array($response->status(), self::RETRYABLE_STATUS_CODES, true)) {
                    Log::warning('TMDb API returned retryable status', [
                        'path' => $path,
                        'status' => $response->status(),
                        'attempt' => $attempt,
                    ]);
                    $this->sleepBeforeRetry($attempt, $response->status());

                    continue;
                }

                Log::warning('TMDb API request failed', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            } catch (ConnectionException $e) {
                Log::warning('TMDb API connection error', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                $this->sleepBeforeRetry($attempt);
            }
        }

        if ($lastResponse instanceof Response) {
            Log::error('TMDb API request exhausted retries', [
                'path' => $path,
                'status' => $lastResponse->status(),
                'body' => $lastResponse->json(),
            ]);
        }

        return null;
    }

    private function sleepBeforeRetry(int $attempt, ?int $statusOrCode = null): void
    {
        $seconds = min(2 ** $attempt, 30);

        if ($statusOrCode === 429 || $statusOrCode === 43) {
            $seconds = max($seconds, 10);
        }

        sleep($seconds);
    }
}
