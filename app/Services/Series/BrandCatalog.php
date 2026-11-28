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
     * @param  list<array<string, mixed>>  $extraMovies  歴代ランキング外の作品（シリーズ・ブランド追加分）。
     *                                                   allFilmsRegions に global があるブランドだけが使う。
     * @return array<string, mixed>
     */
    public static function world(array $rankingMovies, array $extraMovies = []): array
    {
        return self::build(array_merge($rankingMovies, $extraMovies), 'global');
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

    /**
     * 歴代ランキングに入っていない作品も含めて、劇場公開作品をすべて載せるブランドか。
     * map.json の allFilmsRegions に地域（global / japan）を書いたブランドだけが対象。
     *
     * @param  array<string, mixed>  $brand
     */
    public static function listsAllFilms(array $brand, string $region): bool
    {
        $regions = $brand['allFilmsRegions'] ?? [];

        return is_array($regions) && in_array($region, $regions, true);
    }

    /**
     * 全作品を載せるブランドの tmdbIds（除外指定を除く）。取得側が歴代ランキング外の作品を足すのに使う。
     *
     * @param  list<array<string, mixed>>|null  $definitions  省略時は map.json
     * @return array<int, string> tmdbId => ブランドの slug
     */
    public static function allFilmsTmdbIds(string $region, ?array $definitions = null): array
    {
        $ids = [];
        foreach ($definitions ?? self::definitions() as $brand) {
            if (! is_array($brand) || ! self::listsAllFilms($brand, $region)) {
                continue;
            }
            $slug = (string) ($brand['slug'] ?? '');
            $excluded = self::intSet($brand['excludeTmdbIds'] ?? []);
            foreach (array_keys(self::intSet($brand['tmdbIds'] ?? [])) as $id) {
                if ($slug !== '' && ! isset($excluded[$id]) && ! isset($ids[$id])) {
                    $ids[$id] = $slug;
                }
            }
        }

        return $ids;
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
     * @param  list<array<string, mixed>>  $movies
     * @return array<string, mixed>
     */
    private static function build(array $movies, string $region): array
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

        foreach ($movies as $movie) {
            if (! is_array($movie)) {
                continue;
            }
            $brand = self::match($movie);
            if ($brand === null) {
                continue;
            }
            $slug = (string) $brand['slug'];
            if (! isset($grouped[$slug])) {
                continue;
            }
            $allFilms = self::listsAllFilms($brand, $region);
            if (! self::belongsOnPage($movie, $brand, $allFilms, $isJapan)) {
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
            $allFilms = self::listsAllFilms($definition, $region);
            $total = 0;
            $known = 0;
            foreach ($films as $film) {
                $amount = (int) ($film['boxOffice'] ?? 0);
                if ($amount > 0) {
                    $total += $amount;
                    $known++;
                }
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
                'knownCount' => $known,
                'excludedUnknownCount' => count($films) - $known,
                'allFilms' => $allFilms,
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
     * 通常のブランドは歴代ランキング内で興収が分かる作品だけ。
     * 全作品を載せるブランドは、ランキング外でも tmdbIds に明記した公開済み作品なら興収不明でも載せる。
     *
     * @param  array<string, mixed>  $movie
     * @param  array<string, mixed>  $brand
     */
    private static function belongsOnPage(array $movie, array $brand, bool $allFilms, bool $isJapan): bool
    {
        if (SeriesCatalog::filmIsUnreleased($movie)) {
            return false;
        }
        $inRanking = ! empty($movie['inRanking'] ?? true);
        if ($allFilms) {
            if ($inRanking) {
                return true;
            }
            $tmdbId = self::tmdbId($movie);

            return $tmdbId !== null && isset(self::intSet($brand['tmdbIds'] ?? [])[$tmdbId]);
        }
        if (! $inRanking || ! SeriesCatalog::filmHasDisplayableAmount($movie)) {
            return false;
        }

        return $isJapan || (int) ($movie['boxOffice'] ?? 0) !== 1_000_000_000;
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>
     */
    private static function filmRow(array $movie, bool $isJapan, string $brandSlug): array
    {
        $id = (string) ($movie['id'] ?? '');
        $boxOffice = (int) ($movie['boxOffice'] ?? 0);
        $known = $boxOffice > 0 && ($isJapan || $boxOffice !== 1_000_000_000);
        if (! $known) {
            $boxOffice = 0;
        }
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
            'revenue' => $known ? ($movie['revenue'] ?? null) : '不明',
            'revenueBillion' => $known ? ($movie['revenueBillion'] ?? null) : null,
            'revenueYen' => $known ? ($movie['revenueYen'] ?? null) : null,
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
