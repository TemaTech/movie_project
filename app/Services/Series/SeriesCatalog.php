<?php

namespace App\Services\Series;

class SeriesCatalog
{
    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return array{series: list<array<string, mixed>>, movieLinks: array<string, array{href: string, name: string}>, extraMovies: list<array<string, mixed>>, retrievedAt: ?string}
     */
    public static function japan(array $rankingMovies): array
    {
        $snapshot = JapanSeriesSnapshot::load();
        $seriesRows = JapanSeriesSnapshot::series();
        $claimed = [];
        $movieLinks = [];
        $extraMovies = [];
        $built = [];

        $retrievedAt = is_string($snapshot['retrievedAt'] ?? null) ? $snapshot['retrievedAt'] : null;
        foreach ($seriesRows as $row) {
            $row['retrievedAt'] = $retrievedAt;
            $page = self::buildJapanSeries($row, $rankingMovies, $claimed, $extraMovies);
            $built[] = $page;
            foreach ($page['films'] as $film) {
                $id = (string) ($film['id'] ?? '');
                if ($id !== '') {
                    $claimed[$id] = true;
                    $movieLinks[$id] = [
                        'href' => '/series/'.$page['slug'].'/',
                        'name' => $page['name'],
                    ];
                }
            }
        }

        usort($built, fn (array $a, array $b) => ($b['totalYen'] ?? 0) <=> ($a['totalYen'] ?? 0));

        return [
            'region' => 'japan',
            'indexPath' => '/series/',
            'retrievedAt' => is_string($snapshot['retrievedAt'] ?? null) ? $snapshot['retrievedAt'] : null,
            'sourceUrl' => $snapshot['sourceUrl'] ?? JapanSeriesWikipediaParser::SOURCE_URL,
            'licenseUrl' => $snapshot['licenseUrl'] ?? 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja',
            'series' => $built,
            'movieLinks' => $movieLinks,
            'extraMovies' => $extraMovies,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @param  list<array<string, mixed>>  $extraMovies
     * @return array{series: list<array<string, mixed>>, movieLinks: array<string, array{href: string, name: string}>, extraMovies: list<array<string, mixed>>}
     */
    public static function world(array $rankingMovies, array $extraMovies = []): array
    {
        $byCollection = [];
        foreach (array_merge($rankingMovies, $extraMovies) as $movie) {
            $collectionId = $movie['collectionId'] ?? null;
            $collectionName = trim((string) ($movie['collectionName'] ?? ''));
            if (! is_numeric($collectionId) || (int) $collectionId <= 0 || $collectionName === '') {
                continue;
            }
            $collectionId = (int) $collectionId;
            $byCollection[$collectionId]['name'] = $collectionName;
            $byCollection[$collectionId]['id'] = $collectionId;
            $byCollection[$collectionId]['films'][] = $movie;
        }

        $usedSlugs = [];
        $built = [];
        $movieLinks = [];

        foreach ($byCollection as $collection) {
            $films = $collection['films'] ?? [];
            usort($films, function (array $a, array $b): int {
                $yearA = $a['releaseYear'] ?? 9999;
                $yearB = $b['releaseYear'] ?? 9999;
                if ($yearA !== $yearB) {
                    return $yearA <=> $yearB;
                }

                return ((int) ($b['boxOffice'] ?? 0)) <=> ((int) ($a['boxOffice'] ?? 0));
            });

            $slug = SeriesSlug::unique((string) $collection['name'], $usedSlugs);
            $usedSlugs[] = $slug;
            $total = 0;
            $known = 0;
            $rows = [];
            foreach ($films as $film) {
                $yenOrUsd = (int) ($film['boxOffice'] ?? 0);
                $hasFigure = $yenOrUsd > 0 && $yenOrUsd !== 1_000_000_000;
                if ($hasFigure) {
                    $total += $yenOrUsd;
                    $known++;
                }
                $inRanking = ($film['inRanking'] ?? true) && is_numeric($film['rank'] ?? null) && (int) $film['rank'] <= 200;
                $rows[] = [
                    'id' => $film['id'] ?? null,
                    'title' => $film['title'] ?? '',
                    'releaseYear' => $film['releaseYear'] ?? null,
                    'releaseDate' => $film['releaseDate'] ?? null,
                    'boxOffice' => $hasFigure ? $yenOrUsd : null,
                    'revenue' => $hasFigure ? ($film['revenue'] ?? null) : null,
                    'rank' => $inRanking ? ($film['rank'] ?? null) : null,
                    'metric' => 'box_office',
                    'kind' => 'theatrical',
                    'href' => isset($film['id']) ? '/movies/'.$film['id'].'/' : null,
                    'inRanking' => $inRanking,
                ];
                if (! empty($film['id'])) {
                    $movieLinks[(string) $film['id']] = [
                        'href' => '/series/global/'.$slug.'/',
                        'name' => (string) $collection['name'],
                    ];
                }
            }

            $built[] = [
                'slug' => $slug,
                'name' => $collection['name'],
                'path' => '/series/global/'.$slug.'/',
                'region' => 'global',
                'collectionId' => $collection['id'],
                'filmCount' => count($rows),
                'knownCount' => $known,
                'totalYen' => null,
                'totalUsd' => $total > 0 ? $total : null,
                'reportedTotalYen' => null,
                'hasRentals' => false,
                'films' => $rows,
            ];
        }

        usort($built, fn (array $a, array $b) => ($b['totalUsd'] ?? 0) <=> ($a['totalUsd'] ?? 0));

        return [
            'region' => 'global',
            'indexPath' => '/series/global/',
            'retrievedAt' => null,
            'sourceUrl' => 'https://www.themoviedb.org/',
            'licenseUrl' => null,
            'series' => $built,
            'movieLinks' => $movieLinks,
            'extraMovies' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $rankingMovies
     * @param  array<string, true>  $claimed
     * @param  list<array<string, mixed>>  $extraMovies
     * @return array<string, mixed>
     */
    private static function buildJapanSeries(array $row, array $rankingMovies, array &$claimed, array &$extraMovies): array
    {
        $wikiFilms = is_array($row['films'] ?? null) ? $row['films'] : [];
        $films = [];
        $usedRanking = [];

        foreach ($wikiFilms as $wikiFilm) {
            if (($wikiFilm['kind'] ?? 'theatrical') === 'theatrical' || in_array($wikiFilm['kind'] ?? '', ['compilation', 'crossover'], true)) {
                $matched = SeriesFilmMatcher::match($wikiFilm, $rankingMovies);
                $films[] = self::japanFilmRow($row, $wikiFilm, $matched, $extraMovies);
                if ($matched && isset($matched['id'])) {
                    $usedRanking[(string) $matched['id']] = true;
                    $claimed[(string) $matched['id']] = true;
                }
            }
        }

        if ($films === []) {
            foreach (SeriesFilmMatcher::rankingMembers($row, $rankingMovies, $claimed) as $movie) {
                $id = (string) ($movie['id'] ?? '');
                $films[] = self::japanFilmRow($row, [
                    'title' => $movie['title'] ?? '',
                    'releaseYear' => $movie['releaseYear'] ?? null,
                    'releaseDate' => $movie['releaseDate'] ?? null,
                    'boxOfficeYen' => $movie['boxOffice'] ?? null,
                    'metric' => JapanBoxOfficeAmount::METRIC_BOX_OFFICE,
                    'kind' => 'theatrical',
                    'unclear' => false,
                ], $movie, $extraMovies);
                if ($id !== '') {
                    $claimed[$id] = true;
                    $usedRanking[$id] = true;
                }
            }
        }

        $sumBoxOffice = 0;
        $sumRentals = 0;
        $known = 0;
        $hasRentals = false;
        foreach ($films as $film) {
            if (! empty($film['boxOffice'])) {
                $known++;
                if (($film['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS) {
                    $sumRentals += (int) $film['boxOffice'];
                    $hasRentals = true;
                } else {
                    $sumBoxOffice += (int) $film['boxOffice'];
                }
            }
        }

        $reported = is_numeric($row['reportedTotalYen'] ?? null) ? (int) $row['reportedTotalYen'] : null;

        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'path' => '/series/'.$row['slug'].'/',
            'region' => 'japan',
            'wikipediaUrl' => $row['wikipediaUrl'] ?? null,
            'startYear' => $row['startYear'] ?? null,
            'notes' => $row['notes'] ?? null,
            'filmCount' => count($films),
            'knownCount' => $known,
            'totalYen' => $reported ?? ($sumBoxOffice > 0 ? $sumBoxOffice : null),
            'sumBoxOfficeYen' => $sumBoxOffice > 0 ? $sumBoxOffice : null,
            'sumRentalsYen' => $sumRentals > 0 ? $sumRentals : null,
            'reportedTotalYen' => $reported,
            'hasRentals' => $hasRentals,
            'films' => $films,
        ];
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $wikiFilm
     * @param  array<string, mixed>|null  $matched
     * @param  list<array<string, mixed>>  $extraMovies
     * @return array<string, mixed>
     */
    private static function japanFilmRow(array $series, array $wikiFilm, ?array $matched, array &$extraMovies): array
    {
        $title = (string) ($wikiFilm['title'] ?? '');
        $year = $wikiFilm['releaseYear'] ?? null;
        $wikiYen = is_numeric($wikiFilm['boxOfficeYen'] ?? null) ? (int) $wikiFilm['boxOfficeYen'] : null;
        $metric = (string) ($wikiFilm['metric'] ?? JapanBoxOfficeAmount::METRIC_BOX_OFFICE);
        $inRanking = false;
        $rank = null;
        $id = null;
        $href = null;
        $releaseDate = $wikiFilm['releaseDate'] ?? null;
        $boxOffice = $wikiYen;

        if ($matched) {
            $id = (string) ($matched['id'] ?? '');
            $href = $id !== '' ? '/movies/'.$id.'/' : null;
            $rank = is_numeric($matched['rank'] ?? null) ? (int) $matched['rank'] : null;
            $inRanking = $rank !== null && $rank <= 200;
            $title = (string) ($matched['title'] ?? $title);
            $releaseDate = $matched['releaseDate'] ?? $releaseDate;
            $year = $matched['releaseYear'] ?? $year;
            if (is_numeric($matched['boxOffice'] ?? null) && (int) $matched['boxOffice'] > 0) {
                $boxOffice = (int) $matched['boxOffice'];
                $metric = JapanBoxOfficeAmount::METRIC_BOX_OFFICE;
            }
        } else {
            $id = self::extraJapanId($title, $year);
            $href = '/movies/'.$id.'/';
            $extraMovies[] = [
                'id' => $id,
                'slug' => $id,
                'title' => $title,
                'rank' => null,
                'revenue' => $boxOffice ? number_format($boxOffice / 100000000, 1).'億円' : '不明',
                'boxOffice' => $boxOffice ?? 0,
                'releaseDate' => $releaseDate,
                'releaseYear' => $year,
                'releaseDatePrecision' => $releaseDate ? 'day' : ($year ? 'year' : null),
                'posterUrl' => '',
                'genres' => [],
                'originalTitle' => $wikiFilm['wikipediaTitle'] ?? '',
                'updatedAt' => is_string($series['retrievedAt'] ?? null) ? $series['retrievedAt'].'T00:00:00+09:00' : null,
                'seriesSlug' => $series['slug'] ?? null,
            ];
        }

        return [
            'id' => $id,
            'title' => $title,
            'releaseYear' => $year,
            'releaseDate' => $releaseDate,
            'boxOffice' => $boxOffice,
            'revenue' => $boxOffice ? number_format($boxOffice / 100000000, 1).'億円' : null,
            'rank' => $inRanking ? $rank : null,
            'metric' => $metric,
            'kind' => $wikiFilm['kind'] ?? 'theatrical',
            'unclear' => ! empty($wikiFilm['unclear']) && $boxOffice === null,
            'href' => $href,
            'inRanking' => $inRanking,
        ];
    }

    public static function extraJapanId(string $title, mixed $year): string
    {
        return 'jp-series-'.substr(hash('sha256', mb_strtolower(trim($title)).'|'.($year ?? '')), 0, 12);
    }
}
