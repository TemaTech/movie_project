<?php

namespace App\Services\Series;

class BrandCatalog
{
    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return array<string, mixed>
     */
    public static function japan(array $rankingMovies): array
    {
        return self::build($rankingMovies, 'japan');
    }

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return array<string, mixed>
     */
    public static function world(array $rankingMovies): array
    {
        return self::build($rankingMovies, 'global');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $path = base_path('data/brands/map.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_array($decoded['brands'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $decoded['brands'],
            fn (mixed $row): bool => is_array($row) && ($row['slug'] ?? '') !== ''
        ));
    }

    public static function minFilms(): int
    {
        $path = base_path('data/brands/map.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        $min = is_array($decoded) && is_numeric($decoded['minFilms'] ?? null)
            ? (int) $decoded['minFilms']
            : 3;

        return max(2, $min);
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return list<array<string, mixed>>
     */
    public static function matchingBrands(array $movie): array
    {
        $tmdbId = self::tmdbId($movie);
        $collectionId = is_numeric($movie['collectionId'] ?? null) ? (int) $movie['collectionId'] : null;
        $titles = self::movieTitles($movie);
        $hits = [];

        foreach (self::definitions() as $brand) {
            $slug = (string) ($brand['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $excluded = self::intSet($brand['excludeTmdbIds'] ?? []);
            if ($tmdbId !== null && isset($excluded[$tmdbId])) {
                continue;
            }
            $matched = false;
            if ($tmdbId !== null && isset(self::intSet($brand['tmdbIds'] ?? [])[$tmdbId])) {
                $matched = true;
            } elseif ($collectionId !== null && isset(self::intSet($brand['collectionIds'] ?? [])[$collectionId])) {
                $matched = true;
            } elseif (self::titleMatches($titles, is_array($brand['titleAliases'] ?? null) ? $brand['titleAliases'] : [])) {
                $matched = true;
            }
            if ($matched) {
                $hits[] = $brand;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    public static function match(array $movie): ?array
    {
        $hits = self::matchingBrands($movie);

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return array<string, mixed>
     */
    private static function build(array $rankingMovies, string $region): array
    {
        $isJapan = $region === 'japan';
        $indexPath = $isJapan ? '/series/brand/' : '/series/global/brand/';
        $grouped = [];
        foreach (self::definitions() as $brand) {
            $grouped[(string) $brand['slug']] = [
                'definition' => $brand,
                'films' => [],
            ];
        }

        foreach ($rankingMovies as $movie) {
            if (! is_array($movie) || empty($movie['inRanking'] ?? true)) {
                continue;
            }
            if (! SeriesCatalog::filmHasDisplayableAmount($movie)) {
                continue;
            }
            if (! $isJapan) {
                $box = (int) ($movie['boxOffice'] ?? 0);
                if ($box === 1_000_000_000) {
                    continue;
                }
            }
            $brand = self::match($movie);
            if ($brand === null) {
                continue;
            }
            $slug = (string) $brand['slug'];
            if (! isset($grouped[$slug])) {
                continue;
            }
            $grouped[$slug]['films'][] = self::filmRow($movie, $isJapan, $slug);
        }

        $built = [];
        $movieLinks = [];
        $min = self::minFilms();
        foreach ($grouped as $slug => $bucket) {
            $films = SeriesCatalog::sortFilmsByBoxOffice(SeriesCatalog::dedupeFilms($bucket['films']));
            if (count($films) < $min) {
                continue;
            }
            $definition = $bucket['definition'];
            $name = (string) ($definition['name'] ?? $slug);
            $titleName = (string) ($definition['titleName'] ?? $name);
            $total = 0;
            foreach ($films as $film) {
                $total += (int) ($film['boxOffice'] ?? 0);
            }
            $regionLabel = $isJapan ? '日本' : '世界';
            $heading = $titleName.' 興行収入ランキング（'.$regionLabel.'）';
            $path = $indexPath.$slug.'/';
            $page = [
                'slug' => $slug,
                'name' => $name,
                'titleName' => $titleName,
                'heading' => $heading,
                'path' => $path,
                'region' => $region,
                'kind' => 'brand',
                'filmCount' => count($films),
                'knownCount' => count($films),
                'excludedUnknownCount' => 0,
                'totalYen' => $isJapan ? $total : null,
                'totalUsd' => $isJapan ? null : $total,
                'reportedTotalYen' => null,
                'hasRentals' => false,
                'indexable' => count($films) >= $min,
                'films' => $films,
            ];
            $built[] = $page;
            foreach ($films as $film) {
                $id = (string) ($film['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $movieLinks[$id] = [
                    'href' => $path,
                    'name' => $name,
                ];
            }
        }

        usort(
            $built,
            function (array $a, array $b) use ($isJapan): int {
                $key = $isJapan ? 'totalYen' : 'totalUsd';

                return ((int) ($b[$key] ?? 0)) <=> ((int) ($a[$key] ?? 0));
            }
        );

        return [
            'region' => $region,
            'kind' => 'brand',
            'indexPath' => $indexPath,
            'retrievedAt' => null,
            'sourceUrl' => null,
            'licenseUrl' => null,
            'series' => $built,
            'movieLinks' => $movieLinks,
            'extraMovies' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>
     */
    private static function filmRow(array $movie, bool $isJapan, string $brandSlug): array
    {
        $id = (string) ($movie['id'] ?? '');
        $boxOffice = (int) ($movie['boxOffice'] ?? 0);
        $rank = is_numeric($movie['rank'] ?? null) ? (int) $movie['rank'] : null;
        $inRanking = ($movie['inRanking'] ?? true) && $rank !== null && $rank > 0 && $rank <= 200;

        return [
            'id' => $id !== '' ? $id : null,
            'slug' => $movie['slug'] ?? ($id !== '' ? $id : null),
            'tmdbId' => self::tmdbId($movie),
            'title' => $movie['title'] ?? '',
            'originalTitle' => $movie['originalTitle'] ?? '',
            'releaseYear' => $movie['releaseYear'] ?? null,
            'releaseDate' => $movie['releaseDate'] ?? null,
            'boxOffice' => $boxOffice,
            'revenue' => $movie['revenue'] ?? null,
            'revenueBillion' => $movie['revenueBillion'] ?? null,
            'revenueYen' => $movie['revenueYen'] ?? null,
            'rank' => $inRanking ? $rank : null,
            'metric' => 'box_office',
            'kind' => 'theatrical',
            'href' => $id !== '' ? '/movies/'.$id.'/' : null,
            'inRanking' => $inRanking,
            'posterUrl' => $movie['posterUrl'] ?? '',
            'genres' => is_array($movie['genres'] ?? null) ? $movie['genres'] : [],
            'isActive' => (bool) ($movie['isActive'] ?? false),
            'unreleased' => false,
            'productionCountry' => $isJapan ? ($movie['productionCountry'] ?? '日本') : ($movie['productionCountry'] ?? null),
            'updatedAt' => $movie['updatedAt'] ?? null,
            'pageHistory' => is_array($movie['pageHistory'] ?? null) ? $movie['pageHistory'] : [],
            'brandSlug' => $brandSlug,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    private static function tmdbId(array $movie): ?int
    {
        if (is_numeric($movie['tmdbId'] ?? null) && (int) $movie['tmdbId'] > 0) {
            return (int) $movie['tmdbId'];
        }

        $id = (string) ($movie['id'] ?? '');
        if (preg_match('/(?:^|-)tmdb-(\d+)$/', $id, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return list<string>
     */
    private static function movieTitles(array $movie): array
    {
        $titles = [];
        foreach (['title', 'originalTitle'] as $field) {
            $value = trim((string) ($movie[$field] ?? ''));
            if ($value !== '') {
                $titles[] = $value;
            }
        }

        return $titles;
    }

    /**
     * @param  list<string>  $titles
     * @param  list<mixed>  $aliases
     */
    private static function titleMatches(array $titles, array $aliases): bool
    {
        $compactTitles = [];
        foreach ($titles as $title) {
            $compact = SeriesFilmMatcher::identityCompact($title);
            if ($compact !== '') {
                $compactTitles[] = $compact;
            }
        }
        if ($compactTitles === []) {
            return false;
        }
        foreach ($aliases as $alias) {
            $alias = SeriesFilmMatcher::identityCompact((string) $alias);
            if ($alias === '' || mb_strlen($alias) < 4) {
                continue;
            }
            foreach ($compactTitles as $title) {
                if ($title === $alias || str_starts_with($title, $alias) || str_contains($title, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, true>
     */
    private static function intSet(mixed $values): array
    {
        $set = [];
        if (! is_array($values)) {
            return $set;
        }
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $set[(int) $value] = true;
            }
        }

        return $set;
    }
}
