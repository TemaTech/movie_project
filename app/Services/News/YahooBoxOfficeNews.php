<?php

namespace App\Services\News;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 公開中の作品について、Yahoo!ニュースに出ている興行収入記事を探す。
 * Google News の RSS は個人のフィードリーダー以外の利用が禁止されているため使わない。
 */
class YahooBoxOfficeNews
{
    private const CACHE_TTL_SECONDS = 36000;

    private const REQUEST_DELAY_MICROSECONDS = 400000;

    private bool $stopped = false;

    public function __construct(
        private YahooNewsPageParser $parser = new YahooNewsPageParser(),
        private BoxOfficeNewsMatcher $matcher = new BoxOfficeNewsMatcher(),
        private ?string $cachePath = null,
    ) {}

    /**
     * @param  list<array{key: string, title: string, originalTitle?: ?string}>  $movies
     * @return array<string, list<array{title: string, url: string, source: string, publishedAt: ?string}>>
     */
    public function forMovies(array $movies): array
    {
        $found = [];
        foreach ($movies as $movie) {
            if ($this->stopped) {
                break;
            }
            $key = trim((string) ($movie['key'] ?? ''));
            $title = trim((string) ($movie['title'] ?? ''));
            if ($key === '' || $title === '') {
                continue;
            }
            $original = trim((string) ($movie['originalTitle'] ?? ''));
            $articles = $this->search($title.' 興行収入');
            $matched = $this->matcher->match($title, $original !== '' ? $original : null, $articles);
            if ($matched === [] && $original !== '' && mb_strtolower($original) !== mb_strtolower($title) && ! $this->stopped) {
                $matched = $this->matcher->match(
                    $title,
                    $original,
                    array_merge($articles, $this->search($original.' 興行収入')),
                );
            }
            if ($matched !== []) {
                $found[$key] = $matched;
            }
        }

        return $found;
    }

    /**
     * @return list<array{title: string, url: string, source: string, snippet: string, publishedAt: ?string}>
     */
    private function search(string $query): array
    {
        if ($this->stopped) {
            return [];
        }

        $cached = $this->cached($query);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $contact = (string) config('app.contact_email', 'contact@mubiran.com');
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'MUBIRAN/1.0 (+https://mubiran.com; '.$contact.')',
                    'Accept' => 'text/html',
                    'Accept-Language' => 'ja',
                ])
                ->get('https://news.yahoo.co.jp/search', [
                    'p' => $query,
                    'ei' => 'UTF-8',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Yahoo news search failed', ['query' => $query, 'error' => $exception->getMessage()]);

            return [];
        }

        if (in_array($response->status(), [403, 429, 503], true)) {
            $this->stopped = true;
            Log::warning('Yahoo news search stopped', ['status' => $response->status(), 'query' => $query]);

            return [];
        }
        if (! $response->successful()) {
            Log::warning('Yahoo news search rejected', ['status' => $response->status(), 'query' => $query]);

            return [];
        }

        $html = $response->body();
        $articles = $this->parser->parse($html);
        if ($articles === [] && strlen($html) > 20000 && ! str_contains($html, 'newsFeed_list')) {
            Log::warning('Yahoo news search HTML had no results list', ['query' => $query]);

            return [];
        }

        $this->store($query, $articles);
        usleep(self::REQUEST_DELAY_MICROSECONDS);

        return $articles;
    }

    /**
     * @return list<array{title: string, url: string, source: string, snippet: string, publishedAt: ?string}>|null
     */
    private function cached(string $query): ?array
    {
        $entry = $this->read()['queries'][$query] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        $fetchedAt = (int) ($entry['fetchedAt'] ?? 0);
        if ($fetchedAt <= 0 || (time() - $fetchedAt) > self::CACHE_TTL_SECONDS) {
            return null;
        }
        $articles = $entry['articles'] ?? null;

        return is_array($articles) ? $articles : null;
    }

    /**
     * @param  list<array{title: string, url: string, source: string, snippet: string, publishedAt: ?string}>  $articles
     */
    private function store(string $query, array $articles): void
    {
        if ($this->cachePath === null) {
            return;
        }
        $cache = $this->read();
        $cache['queries'][$query] = [
            'fetchedAt' => time(),
            'articles' => $articles,
        ];
        $directory = dirname($this->cachePath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }
        file_put_contents(
            $this->cachePath,
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{queries: array<string, mixed>}
     */
    private function read(): array
    {
        if ($this->cachePath === null || ! is_file($this->cachePath)) {
            return ['queries' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->cachePath), true);
        if (! is_array($decoded) || ! is_array($decoded['queries'] ?? null)) {
            return ['queries' => []];
        }

        return ['queries' => $decoded['queries']];
    }
}
