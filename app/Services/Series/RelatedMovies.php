<?php

namespace App\Services\Series;

class RelatedMovies
{
    public const MAX = 5;

    /**
     * @param  array<string, mixed>  $movie
     * @param  array{
     *     series?: array<string, list<array<string, mixed>>>,
     *     brand?: array<string, list<array<string, mixed>>>,
     *     ranking?: list<array<string, mixed>>
     * }  $indexes
     * @return list<array{id: string, title: string, href: string, revenue: ?string}>
     */
    public static function pick(array $movie, array $indexes): array
    {
        $id = (string) ($movie['id'] ?? '');
        if ($id === '') {
            return [];
        }

        $seen = [$id => true];
        $picked = [];

        foreach (self::adjacentByRelease($indexes['series'][$id] ?? [], $id) as $item) {
            self::add($picked, $seen, $item);
            if (count($picked) >= self::MAX) {
                return $picked;
            }
        }

        foreach (self::othersByBoxOffice($indexes['brand'][$id] ?? [], $id) as $item) {
            self::add($picked, $seen, $item);
            if (count($picked) >= self::MAX) {
                return $picked;
            }
        }

        foreach (self::nearbyRank($indexes['ranking'] ?? [], $id) as $item) {
            self::add($picked, $seen, $item);
            if (count($picked) >= self::MAX) {
                return $picked;
            }
        }

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $seriesPages
     * @param  list<array<string, mixed>>  $brandPages
     * @param  list<array<string, mixed>>  $globalRanking
     * @param  list<array<string, mixed>>  $japanRanking
     * @param  array<string, true>  $exportableIds
     * @return array{
     *     series: array<string, list<array<string, mixed>>>,
     *     brand: array<string, list<array<string, mixed>>>,
     *     rankingGlobal: list<array<string, mixed>>,
     *     rankingJapan: list<array<string, mixed>>
     * }
     */
    public static function buildIndexes(
        array $seriesPages,
        array $brandPages,
        array $globalRanking,
        array $japanRanking,
        array $exportableIds,
    ): array {
        return [
            'series' => self::membershipIndex($seriesPages, $exportableIds),
            'brand' => self::membershipIndex($brandPages, $exportableIds),
            'rankingGlobal' => self::rankingIndex($globalRanking, $exportableIds),
            'rankingJapan' => self::rankingIndex($japanRanking, $exportableIds),
        ];
    }

    /**
     * @param  list<string>  $movieIds
     * @param  array{
     *     series?: array<string, list<array<string, mixed>>>,
     *     brand?: array<string, list<array<string, mixed>>>,
     *     ranking?: list<array<string, mixed>>
     * }  $indexes
     */
    public static function countFewerThan(array $movieIds, array $indexes, int $min = 3): int
    {
        $count = 0;
        foreach ($movieIds as $id) {
            if (count(self::pick(['id' => $id], $indexes)) < $min) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  array<string, true>  $exportableIds
     * @return array<string, list<array<string, mixed>>>
     */
    public static function membershipIndex(array $pages, array $exportableIds): array
    {
        $index = [];
        foreach ($pages as $page) {
            $films = self::linkableFilms($page['films'] ?? [], $exportableIds);
            foreach ($films as $film) {
                $id = (string) $film['id'];
                $existing = $index[$id] ?? [];
                if (count($films) > count($existing)) {
                    $index[$id] = $films;
                }
            }
        }

        return $index;
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @param  array<string, true>  $exportableIds
     * @return list<array<string, mixed>>
     */
    public static function rankingIndex(array $movies, array $exportableIds): array
    {
        $rows = self::linkableFilms($movies, $exportableIds);
        usort(
            $rows,
            function (array $a, array $b): int {
                $rankA = is_int($a['rank'] ?? null) ? $a['rank'] : PHP_INT_MAX;
                $rankB = is_int($b['rank'] ?? null) ? $b['rank'] : PHP_INT_MAX;
                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }

                return ((int) ($b['boxOffice'] ?? 0)) <=> ((int) ($a['boxOffice'] ?? 0));
            }
        );

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    private static function adjacentByRelease(array $films, string $id): array
    {
        $sorted = $films;
        usort($sorted, fn (array $a, array $b): int => self::releaseSortKey($a) <=> self::releaseSortKey($b));

        $index = null;
        foreach ($sorted as $i => $film) {
            if ((string) ($film['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return [];
        }

        $picked = [];
        $count = count($sorted);
        for ($offset = 1; $offset < $count; $offset++) {
            $left = $index - $offset;
            if ($left >= 0) {
                $picked[] = $sorted[$left];
            }
            $right = $index + $offset;
            if ($right < $count) {
                $picked[] = $sorted[$right];
            }
        }

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    private static function othersByBoxOffice(array $films, string $id): array
    {
        $sorted = array_values(array_filter(
            $films,
            fn (array $film): bool => (string) ($film['id'] ?? '') !== $id
        ));
        usort($sorted, fn (array $a, array $b): int => ((int) ($b['boxOffice'] ?? 0)) <=> ((int) ($a['boxOffice'] ?? 0)));

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $ranking
     * @return list<array<string, mixed>>
     */
    private static function nearbyRank(array $ranking, string $id): array
    {
        $index = null;
        foreach ($ranking as $i => $film) {
            if ((string) ($film['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return [];
        }

        $picked = [];
        $count = count($ranking);
        for ($offset = 1; $offset < $count; $offset++) {
            $left = $index - $offset;
            if ($left >= 0) {
                $picked[] = $ranking[$left];
            }
            $right = $index + $offset;
            if ($right < $count) {
                $picked[] = $ranking[$right];
            }
        }

        return $picked;
    }

    /**
     * @param  list<array<string, mixed>>  $picked
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $item
     */
    private static function add(array &$picked, array &$seen, array $item): void
    {
        $id = (string) ($item['id'] ?? '');
        $href = (string) ($item['href'] ?? '');
        $title = trim((string) ($item['title'] ?? ''));
        if ($id === '' || $href === '' || $title === '' || isset($seen[$id])) {
            return;
        }

        $seen[$id] = true;
        $picked[] = [
            'id' => $id,
            'title' => $title,
            'href' => $href,
            'revenue' => self::revenueLabel($item),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $films
     * @param  array<string, true>  $exportableIds
     * @return list<array<string, mixed>>
     */
    private static function linkableFilms(array $films, array $exportableIds): array
    {
        $rows = [];
        $seen = [];
        foreach ($films as $film) {
            $normalized = self::normalize($film);
            if ($normalized === null || isset($seen[$normalized['id']])) {
                continue;
            }
            if ($exportableIds !== [] && ! isset($exportableIds[$normalized['id']])) {
                continue;
            }
            $seen[$normalized['id']] = true;
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $film
     * @return array{id: string, title: string, href: string, revenue: ?string, releaseDate: ?string, releaseYear: ?int, boxOffice: int, rank: ?int}|null
     */
    private static function normalize(array $film): ?array
    {
        $id = (string) ($film['id'] ?? '');
        $title = trim((string) ($film['title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        $href = trim((string) ($film['href'] ?? ''));
        if ($href === '') {
            $href = '/movies/'.$id.'/';
        }
        if (! str_starts_with($href, '/movies/')) {
            return null;
        }

        $year = $film['releaseYear'] ?? null;

        return [
            'id' => $id,
            'title' => $title,
            'href' => $href,
            'revenue' => self::revenueLabel($film),
            'releaseDate' => is_string($film['releaseDate'] ?? null) && $film['releaseDate'] !== ''
                ? (string) $film['releaseDate']
                : null,
            'releaseYear' => is_numeric($year) ? (int) $year : null,
            'boxOffice' => (int) ($film['boxOffice'] ?? 0),
            'rank' => is_numeric($film['rank'] ?? null) ? (int) $film['rank'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $film
     */
    private static function revenueLabel(array $film): ?string
    {
        $revenue = trim((string) ($film['revenue'] ?? ''));
        if ($revenue === '' || in_array($revenue, ['不明', '公開予定', '-', 'x'], true)) {
            return null;
        }

        return $revenue;
    }

    /**
     * @param  array<string, mixed>  $film
     */
    private static function releaseSortKey(array $film): string
    {
        $date = (string) ($film['releaseDate'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 0, 10).'|'.($film['id'] ?? '');
        }

        $year = $film['releaseYear'] ?? null;
        if (is_numeric($year) && (int) $year > 0) {
            return sprintf('%04d-01-01|%s', (int) $year, $film['id'] ?? '');
        }

        return '9999-12-31|'.($film['id'] ?? '');
    }
}
