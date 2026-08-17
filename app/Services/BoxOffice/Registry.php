<?php

namespace App\Services\BoxOffice;

use App\Services\BoxOffice\Contracts\RegistryRepository;

class Registry implements RegistryRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $movies = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function __construct(private readonly string $path)
    {
        $this->load();
    }

    public function load(): void
    {
        if (! is_file($this->path)) {
            $this->movies = [];
            $this->aliases = [];

            return;
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $this->movies = is_array($decoded['movies'] ?? null) ? $decoded['movies'] : [];
        $this->aliases = is_array($decoded['aliases'] ?? null) ? $decoded['aliases'] : [];
    }

    public function save(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = json_encode([
            'movies' => $this->movies,
            'aliases' => $this->aliases,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        file_put_contents($this->path, $payload."\n", LOCK_EX);
    }

    public function resolveCanonicalKey(string $key): string
    {
        return $this->aliases[$key] ?? $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $canonical = $this->resolveCanonicalKey($key);

        return $this->movies[$canonical] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function movies(): array
    {
        return $this->movies;
    }

    /**
     * @param  array{
     *     region: string,
     *     title: string,
     *     tmdbId?: int|null,
     *     releaseYear?: int|null,
     *     releaseDate?: string|null,
     *     releaseDatePrecision?: string|null,
     *     legacyIds?: list<string>
     * }  $incoming
     * @return array<string, mixed>
     */
    public function resolve(array $incoming): array
    {
        $region = $incoming['region'];
        $title = $incoming['title'];
        $normalized = MovieIdentity::normalizeTitle($title);
        $tmdbId = isset($incoming['tmdbId']) ? (int) $incoming['tmdbId'] : null;
        if ($tmdbId === 0) {
            $tmdbId = null;
        }
        $year = isset($incoming['releaseYear']) ? (int) $incoming['releaseYear'] : null;
        $now = $incoming['now'] ?? gmdate('c');

        $match = $this->findMatch($region, $tmdbId, $normalized, $year);
        if ($match === null) {
            $key = $region === 'japan'
                ? MovieIdentity::japanKey($tmdbId, $normalized, $year)
                : MovieIdentity::globalKey((int) $tmdbId);

            $match = [
                'key' => $key,
                'region' => $region,
                'title' => $title,
                'normalizedTitle' => $normalized,
                'releaseYear' => $year,
                'releaseDate' => $incoming['releaseDate'] ?? null,
                'releaseDatePrecision' => $incoming['releaseDatePrecision'] ?? ($year ? 'year' : null),
                'tmdbId' => $tmdbId,
                'legacyIds' => [],
                'firstSeenAt' => $now,
            ];
        }

        $match['title'] = $title;
        $match['normalizedTitle'] = $normalized;
        if ($tmdbId && empty($match['tmdbId'])) {
            $match['tmdbId'] = $tmdbId;
        }
        if (! empty($incoming['releaseDate']) && ($incoming['releaseDatePrecision'] ?? '') === 'day') {
            $match['releaseDate'] = $incoming['releaseDate'];
            $match['releaseDatePrecision'] = 'day';
            $match['releaseYear'] = (int) substr($incoming['releaseDate'], 0, 4);
        } elseif ($year && empty($match['releaseYear'])) {
            $match['releaseYear'] = $year;
        }
        if (! empty($incoming['releaseDate']) && empty($match['releaseDate'])) {
            $match['releaseDate'] = $incoming['releaseDate'];
            $match['releaseDatePrecision'] = $incoming['releaseDatePrecision'] ?? $match['releaseDatePrecision'] ?? null;
        }

        foreach ($incoming['legacyIds'] ?? [] as $legacyId) {
            if ($legacyId === '' || $legacyId === $match['key']) {
                continue;
            }
            $legacyIds = $match['legacyIds'] ?? [];
            if (! in_array($legacyId, $legacyIds, true)) {
                $legacyIds[] = $legacyId;
            }
            $match['legacyIds'] = $legacyIds;
            $this->aliases[$legacyId] = $match['key'];
        }

        $this->movies[$match['key']] = $match;

        return $match;
    }

    public function rememberLegacyId(string $key, string $legacyId): void
    {
        if ($legacyId === '' || $legacyId === $key) {
            return;
        }

        $this->aliases[$legacyId] = $key;
        $legacyIds = $this->movies[$key]['legacyIds'] ?? [];
        if (! in_array($legacyId, $legacyIds, true)) {
            $legacyIds[] = $legacyId;
            $this->movies[$key]['legacyIds'] = $legacyIds;
        }
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function redirects(): array
    {
        $rules = [];
        foreach ($this->movies as $key => $movie) {
            foreach ($movie['legacyIds'] ?? [] as $legacyId) {
                $fromSlug = str_starts_with((string) $legacyId, 'global_')
                    ? substr((string) $legacyId, strlen('global_'))
                    : (string) $legacyId;
                if ($fromSlug === $key) {
                    continue;
                }
                $rules[] = [
                    'from' => '/movies/'.$fromSlug,
                    'to' => '/movies/'.$key.'/',
                ];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMatch(string $region, ?int $tmdbId, string $normalizedTitle, ?int $year): ?array
    {
        if ($tmdbId) {
            foreach ($this->movies as $movie) {
                if (($movie['region'] ?? null) === $region && (int) ($movie['tmdbId'] ?? 0) === $tmdbId) {
                    return $movie;
                }
            }
        }

        $candidates = [];
        foreach ($this->movies as $movie) {
            if (($movie['region'] ?? null) !== $region) {
                continue;
            }
            if (($movie['normalizedTitle'] ?? '') !== $normalizedTitle) {
                continue;
            }
            $candidates[] = $movie;
        }

        if ($candidates === []) {
            return null;
        }

        if ($year) {
            foreach ($candidates as $movie) {
                if ((int) ($movie['releaseYear'] ?? 0) === $year) {
                    return $movie;
                }
            }
            foreach ($candidates as $movie) {
                $existingYear = (int) ($movie['releaseYear'] ?? 0);
                if ($existingYear && abs($existingYear - $year) <= 1) {
                    return $movie;
                }
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
