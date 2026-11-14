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

        $keptIds = [];
        foreach ($built as $page) {
            foreach ($page['films'] as $film) {
                $id = (string) ($film['id'] ?? '');
                if ($id !== '') {
                    $keptIds[$id] = true;
                }
            }
        }
        $uniqueExtras = [];
        foreach ($extraMovies as $movie) {
            $id = (string) ($movie['id'] ?? '');
            if ($id !== '' && isset($keptIds[$id]) && ! isset($uniqueExtras[$id])) {
                $uniqueExtras[$id] = $movie;
            }
        }
        $extraMovies = array_values($uniqueExtras);

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
            $films = self::sortFilmsByBoxOffice(self::dedupeFilms($collection['films'] ?? []));

            $slug = SeriesSlug::unique((string) $collection['name'], $usedSlugs, (int) $collection['id']);
            $usedSlugs[] = $slug;
            $total = 0;
            $known = 0;
            $rows = [];
            foreach ($films as $film) {
                $yenOrUsd = (int) ($film['boxOffice'] ?? 0);
                $unreleased = self::filmIsUnreleased($film);
                $hasFigure = ! $unreleased && $yenOrUsd > 0 && $yenOrUsd !== 1_000_000_000;
                if ($hasFigure) {
                    $total += $yenOrUsd;
                    $known++;
                }
                $inRanking = ($film['inRanking'] ?? true) && is_numeric($film['rank'] ?? null) && (int) $film['rank'] <= 200;
                $rows[] = [
                    'id' => $film['id'] ?? null,
                    'slug' => $film['slug'] ?? ($film['id'] ?? null),
                    'tmdbId' => $film['tmdbId'] ?? null,
                    'title' => $film['title'] ?? '',
                    'originalTitle' => $film['originalTitle'] ?? '',
                    'releaseYear' => $film['releaseYear'] ?? null,
                    'releaseDate' => $film['releaseDate'] ?? null,
                    'boxOffice' => $hasFigure ? $yenOrUsd : null,
                    'revenue' => $unreleased ? '公開予定' : ($hasFigure ? ($film['revenue'] ?? null) : '不明'),
                    'revenueBillion' => $hasFigure ? ($film['revenueBillion'] ?? null) : null,
                    'revenueYen' => $hasFigure ? ($film['revenueYen'] ?? null) : null,
                    'rank' => ($unreleased || ! $inRanking) ? null : ($film['rank'] ?? null),
                    'metric' => 'box_office',
                    'kind' => 'theatrical',
                    'href' => isset($film['id']) ? '/movies/'.$film['id'].'/' : null,
                    'inRanking' => $unreleased ? false : $inRanking,
                    'posterUrl' => $film['posterUrl'] ?? '',
                    'genres' => is_array($film['genres'] ?? null) ? $film['genres'] : [],
                    'isActive' => $unreleased ? false : (bool) ($film['isActive'] ?? false),
                    'unreleased' => $unreleased,
                    'productionCountry' => $film['productionCountry'] ?? null,
                    'updatedAt' => $film['updatedAt'] ?? null,
                    'pageHistory' => is_array($film['pageHistory'] ?? null) ? $film['pageHistory'] : [],
                ];
                if (! empty($film['id'])) {
                    $movieLinks[(string) $film['id']] = [
                        'href' => '/series/global/'.$slug.'/',
                        'name' => (string) $collection['name'],
                    ];
                }
            }

            $totalUsd = $total > 0 ? $total : null;
            $built[] = [
                'slug' => $slug,
                'name' => $collection['name'],
                'path' => '/series/global/'.$slug.'/',
                'region' => 'global',
                'collectionId' => $collection['id'],
                'filmCount' => count($rows),
                'knownCount' => $known,
                'totalYen' => null,
                'totalUsd' => $totalUsd,
                'reportedTotalYen' => null,
                'hasRentals' => false,
                'indexable' => self::isIndexable(count($rows), $known, $totalUsd),
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

        foreach ($wikiFilms as $wikiFilm) {
            if (SeriesFilmMatcher::isNonFilmRowTitle((string) ($wikiFilm['title'] ?? ''))) {
                continue;
            }
            if (($wikiFilm['kind'] ?? 'theatrical') === 'theatrical' || in_array($wikiFilm['kind'] ?? '', ['compilation', 'crossover'], true)) {
                $matchResult = SeriesFilmMatcher::matchAll($wikiFilm, $rankingMovies);
                $matched = $matchResult['matches'][0] ?? null;
                if ($matched && isset($matched['id']) && isset($claimed[(string) $matched['id']])) {
                    $matched = null;
                }
                $films[] = self::japanFilmRow($row, $wikiFilm, $matched, $extraMovies);
                if ($matched && isset($matched['id'])) {
                    $claimed[(string) $matched['id']] = true;
                }
            }
        }

        $films = self::sortFilmsByBoxOffice(self::disambiguateDuplicateTitles(self::dedupeFilms($films)));
        $titlesById = [];
        foreach ($films as $film) {
            $id = (string) ($film['id'] ?? '');
            if ($id !== '') {
                $titlesById[$id] = (string) ($film['title'] ?? '');
            }
        }
        foreach ($extraMovies as &$extra) {
            if (($extra['seriesSlug'] ?? null) !== ($row['slug'] ?? null)) {
                continue;
            }
            $extraId = (string) ($extra['id'] ?? '');
            if ($extraId !== '' && isset($titlesById[$extraId])) {
                $extra['title'] = $titlesById[$extraId];
            }
        }
        unset($extra);

        $sumBoxOffice = 0;
        $sumRentals = 0;
        $known = 0;
        $hasRentals = false;
        foreach ($films as $film) {
            if (! empty($film['unreleased']) || empty($film['boxOffice'])) {
                continue;
            }
            $known++;
            if (($film['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS) {
                $sumRentals += (int) $film['boxOffice'];
                $hasRentals = true;
            } else {
                $sumBoxOffice += (int) $film['boxOffice'];
            }
        }

        $reported = is_numeric($row['reportedTotalYen'] ?? null) ? (int) $row['reportedTotalYen'] : null;
        $totalYen = $reported ?? ($sumBoxOffice > 0 ? $sumBoxOffice : null);

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
            'totalYen' => $totalYen,
            'sumBoxOfficeYen' => $sumBoxOffice > 0 ? $sumBoxOffice : null,
            'sumRentalsYen' => $sumRentals > 0 ? $sumRentals : null,
            'reportedTotalYen' => $reported,
            'hasRentals' => $hasRentals,
            'indexable' => self::isIndexable(count($films), $known, $totalYen),
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
        $title = SeriesFilmMatcher::displayTitle((string) ($wikiFilm['title'] ?? ''));
        $year = $wikiFilm['releaseYear'] ?? null;
        $wikiYen = is_numeric($wikiFilm['boxOfficeYen'] ?? null) ? (int) $wikiFilm['boxOfficeYen'] : null;
        $metric = (string) ($wikiFilm['metric'] ?? JapanBoxOfficeAmount::METRIC_BOX_OFFICE);
        $inRanking = false;
        $rank = null;
        $id = null;
        $href = null;
        $releaseDate = $wikiFilm['releaseDate'] ?? null;
        $boxOffice = $wikiYen;
        $updatedAt = is_string($series['retrievedAt'] ?? null) ? $series['retrievedAt'].'T00:00:00+09:00' : null;
        $pageHistory = [];

        $posterUrl = '';
        $originalTitle = (string) ($wikiFilm['wikipediaTitle'] ?? '');
        $genres = [];
        $isActive = false;
        $tmdbId = null;
        $revenueBillion = null;
        $productionCountry = '日本';

        if ($matched) {
            $id = (string) ($matched['id'] ?? '');
            $href = $id !== '' ? '/movies/'.$id.'/' : null;
            $rank = is_numeric($matched['rank'] ?? null) ? (int) $matched['rank'] : null;
            $inRanking = $rank !== null && $rank <= 200;
            $title = SeriesFilmMatcher::displayTitle((string) ($matched['title'] ?? $title));
            $releaseDate = $matched['releaseDate'] ?? $releaseDate;
            $year = $matched['releaseYear'] ?? $year;
            $posterUrl = (string) ($matched['posterUrl'] ?? '');
            $originalTitle = (string) ($matched['originalTitle'] ?? $originalTitle);
            $genres = is_array($matched['genres'] ?? null) ? $matched['genres'] : [];
            $isActive = (bool) ($matched['isActive'] ?? false);
            $tmdbId = is_numeric($matched['tmdbId'] ?? null) ? (int) $matched['tmdbId'] : null;
            $revenueBillion = $matched['revenueBillion'] ?? null;
            $productionCountry = (string) ($matched['productionCountry'] ?? $productionCountry);
            $updatedAt = is_string($matched['updatedAt'] ?? null) ? $matched['updatedAt'] : $updatedAt;
            $pageHistory = is_array($matched['pageHistory'] ?? null) ? $matched['pageHistory'] : [];
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
                'inRanking' => false,
                'revenue' => $boxOffice ? number_format($boxOffice / 100000000, 1).'億円' : '不明',
                'boxOffice' => $boxOffice ?? 0,
                'releaseDate' => $releaseDate,
                'releaseYear' => $year,
                'releaseDatePrecision' => $releaseDate ? 'day' : ($year ? 'year' : null),
                'posterUrl' => '',
                'genres' => [],
                'originalTitle' => $originalTitle,
                'productionCountry' => $productionCountry,
                'updatedAt' => $updatedAt,
                'seriesSlug' => $series['slug'] ?? null,
                'indexable' => is_numeric($boxOffice) && (int) $boxOffice > 0 && ! self::filmIsUnreleased([
                    'releaseDate' => $releaseDate,
                    'releaseYear' => $year,
                ]),
            ];
        }

        $unreleased = self::filmIsUnreleased([
            'releaseDate' => $releaseDate,
            'releaseYear' => $year,
        ]);
        if ($unreleased) {
            $boxOffice = null;
            $inRanking = false;
            $rank = null;
            $isActive = false;
        }

        return [
            'id' => $id,
            'slug' => $id,
            'tmdbId' => $tmdbId,
            'title' => $title,
            'originalTitle' => $originalTitle,
            'releaseYear' => $year,
            'releaseDate' => $releaseDate,
            'boxOffice' => $boxOffice,
            'revenue' => $unreleased
                ? '公開予定'
                : ($boxOffice ? number_format($boxOffice / 100000000, 1).'億円' : '不明'),
            'revenueBillion' => $unreleased ? null : $revenueBillion,
            'rank' => $inRanking ? $rank : null,
            'metric' => $metric,
            'kind' => $wikiFilm['kind'] ?? 'theatrical',
            'unclear' => ! empty($wikiFilm['unclear']) && $boxOffice === null && ! $unreleased,
            'href' => $href,
            'inRanking' => $inRanking,
            'posterUrl' => $posterUrl,
            'genres' => $genres,
            'isActive' => $isActive,
            'unreleased' => $unreleased,
            'productionCountry' => $productionCountry,
            'updatedAt' => $updatedAt,
            'pageHistory' => $pageHistory,
        ];
    }

    /**
     * 同一シリーズ内の重複を TMDB id、なければ正規化タイトル+公開年でまとめる。
     * 歴代ランキングの行を優先し、興収・ポスターがある方を残す。
     *
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    public static function dedupeFilms(array $films): array
    {
        $kept = [];
        $indexByKey = [];
        foreach ($films as $film) {
            if (! is_array($film)) {
                continue;
            }
            $keys = SeriesFilmMatcher::dedupeKeys($film);
            $existingIndex = null;
            foreach ($keys as $key) {
                if (isset($indexByKey[$key])) {
                    $existingIndex = $indexByKey[$key];
                    break;
                }
            }
            if ($existingIndex === null) {
                foreach ($kept as $index => $existing) {
                    if (SeriesFilmMatcher::isSameFilm($existing, $film)) {
                        $existingIndex = $index;
                        break;
                    }
                }
            }
            if ($existingIndex === null) {
                $kept[] = $film;
                $index = count($kept) - 1;
                foreach ($keys as $key) {
                    $indexByKey[$key] = $index;
                }

                continue;
            }

            $existing = $kept[$existingIndex];
            $preferred = self::filmQualityScore($film) > self::filmQualityScore($existing) ? $film : $existing;
            $other = $preferred === $film ? $existing : $film;
            $kept[$existingIndex] = self::mergeFilmFields($preferred, $other);
            foreach (SeriesFilmMatcher::dedupeKeys($kept[$existingIndex]) as $key) {
                $indexByKey[$key] = $existingIndex;
            }
        }

        return array_values($kept);
    }

    /**
     * 同一シリーズ内で表示タイトルが衝突するときだけ公開年を付ける。
     *
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    public static function disambiguateDuplicateTitles(array $films): array
    {
        $counts = [];
        foreach ($films as $film) {
            $key = SeriesFilmMatcher::identityCompact((string) ($film['title'] ?? ''));
            if ($key !== '') {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        foreach ($films as &$film) {
            $key = SeriesFilmMatcher::identityCompact((string) ($film['title'] ?? ''));
            if ($key === '' || ($counts[$key] ?? 0) < 2) {
                continue;
            }
            $year = SeriesFilmMatcher::year($film);
            if ($year === null) {
                continue;
            }
            $title = SeriesFilmMatcher::displayTitle((string) ($film['title'] ?? ''));
            if (! str_contains($title, (string) $year)) {
                $film['title'] = $title.' ('.$year.')';
            }
        }
        unset($film);

        return $films;
    }

    /**
     * @param  array<string, mixed>  $film
     */
    private static function filmQualityScore(array $film): int
    {
        $score = 0;
        $rank = $film['rank'] ?? null;
        $inRanking = ! empty($film['inRanking'])
            || (is_numeric($rank) && (int) $rank > 0 && (int) $rank <= 200);
        if ($inRanking) {
            $score += 1000;
            if (is_numeric($rank)) {
                $score += max(0, 200 - (int) $rank);
            }
        }
        $boxOffice = (int) ($film['boxOffice'] ?? $film['boxOfficeYen'] ?? 0);
        if ($boxOffice > 0 && $boxOffice !== 1_000_000_000) {
            $score += 200;
        }
        if (trim((string) ($film['posterUrl'] ?? '')) !== '') {
            $score += 50;
        }
        if (SeriesFilmMatcher::tmdbId($film) !== null) {
            $score += 20;
        }
        $id = (string) ($film['id'] ?? '');
        if ($id !== '' && ! str_starts_with($id, 'jp-series-')) {
            $score += 10;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $preferred
     * @param  array<string, mixed>  $other
     * @return array<string, mixed>
     */
    private static function mergeFilmFields(array $preferred, array $other): array
    {
        foreach ($other as $key => $value) {
            if (! array_key_exists($key, $preferred) || self::isBlankFilmValue($preferred[$key] ?? null)) {
                if (! self::isBlankFilmValue($value)) {
                    $preferred[$key] = $value;
                }
            }
        }
        $preferredBox = (int) ($preferred['boxOffice'] ?? 0);
        $otherBox = (int) ($other['boxOffice'] ?? 0);
        if ($preferredBox <= 0 && $otherBox > 0 && $otherBox !== 1_000_000_000) {
            $preferred['boxOffice'] = $other['boxOffice'];
            if (isset($other['metric'])) {
                $preferred['metric'] = $other['metric'];
            }
        }

        return $preferred;
    }

    private static function isBlankFilmValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    /**
     * 興収がある作品を高い順、配給収入のみ・不明は末尾。興収と配収は比べない。
     *
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    public static function sortFilmsByBoxOffice(array $films): array
    {
        usort($films, function (array $a, array $b): int {
            $groupA = self::displayGroup($a);
            $groupB = self::displayGroup($b);
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }
            if ($groupA === 0 || $groupA === 1) {
                $cmp = ((int) ($b['boxOffice'] ?? 0)) <=> ((int) ($a['boxOffice'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return self::filmReleaseKey($a) <=> self::filmReleaseKey($b);
        });

        return array_values($films);
    }

    /**
     * 0 = 興収、1 = 配給収入のみ、2 = 不明、3 = 公開予定。
     *
     * @param  array<string, mixed>  $film
     */
    public static function displayGroup(array $film): int
    {
        if (self::filmIsUnreleased($film)) {
            return 3;
        }
        $boxOffice = $film['boxOffice'] ?? null;
        $hasFigure = is_numeric($boxOffice) && (int) $boxOffice > 0 && (int) $boxOffice !== 1_000_000_000;
        if (! $hasFigure) {
            return 2;
        }
        if (($film['metric'] ?? '') === JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS) {
            return 1;
        }

        return 0;
    }

    /**
     * 公開日がビルド時点より後なら未公開。公開日がなく公開年だけ未来の場合も含む。
     *
     * @param  array<string, mixed>  $film
     */
    public static function filmIsUnreleased(array $film, ?\DateTimeInterface $now = null): bool
    {
        if (array_key_exists('unreleased', $film) && is_bool($film['unreleased'])) {
            return $film['unreleased'];
        }

        $now = $now ?? now('Asia/Tokyo');
        $today = $now->format('Y-m-d');
        $date = $film['releaseDate'] ?? null;
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return substr($date, 0, 10) > $today;
        }
        $year = $film['releaseYear'] ?? null;
        if (is_numeric($year) && (int) $year > (int) $now->format('Y')) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $films
     * @return list<array<string, mixed>>
     */
    public static function sortFilmsByRelease(array $films): array
    {
        usort($films, function (array $a, array $b): int {
            $cmp = self::filmReleaseKey($a) <=> self::filmReleaseKey($b);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($b['boxOffice'] ?? 0)) <=> ((int) ($a['boxOffice'] ?? 0));
        });

        return array_values($films);
    }

    /**
     * @param  array<string, mixed>  $film
     */
    public static function filmReleaseKey(array $film): string
    {
        $date = $film['releaseDate'] ?? null;
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return substr($date, 0, 10);
        }
        $year = $film['releaseYear'] ?? null;
        if (is_numeric($year) && (int) $year > 0) {
            return sprintf('%04d-12-31', (int) $year);
        }

        return '9999-12-31';
    }

    public static function extraJapanId(string $title, mixed $year): string
    {
        return 'jp-series-'.substr(hash('sha256', mb_strtolower(trim($title)).'|'.($year ?? '')), 0, 12);
    }

    public static function isIndexable(int $filmCount, int $knownCount, mixed $total): bool
    {
        if ($filmCount < 2) {
            return false;
        }

        return $knownCount > 0 || (is_numeric($total) && (int) $total > 0);
    }

    /**
     * @param  array<string, mixed>  $movie
     */
    public static function movieIsIndexable(array $movie): bool
    {
        if (array_key_exists('indexable', $movie)) {
            return (bool) $movie['indexable'];
        }
        if (! empty($movie['inRanking']) && is_numeric($movie['rank'] ?? null) && (int) $movie['rank'] > 0) {
            return true;
        }

        $id = (string) ($movie['id'] ?? '');
        $isExtra = str_starts_with($id, 'jp-series-')
            || (array_key_exists('inRanking', $movie) && empty($movie['inRanking']));
        if (! $isExtra) {
            return true;
        }

        $boxOffice = (int) ($movie['boxOffice'] ?? 0);

        return $boxOffice > 0 && $boxOffice !== 1_000_000_000;
    }
}
