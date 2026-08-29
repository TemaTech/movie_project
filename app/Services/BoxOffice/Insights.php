<?php

namespace App\Services\BoxOffice;

use DateInterval;
use DateTimeImmutable;

class Insights
{
    public const RECENT_MILESTONE_WITHIN_DAYS = 30;

    public const TODAY_WITHIN_HOURS = 72;

    /** @var array<int, string> */
    public const PERIOD_LABELS = [
        1 => '昨日から',
        7 => '1週間で',
        30 => '1ヶ月で',
    ];

    /** @var list<int> */
    public const JAPAN_MILESTONES = [
        1_000_000_000,
        3_000_000_000,
        5_000_000_000,
        10_000_000_000,
        15_000_000_000,
        20_000_000_000,
        30_000_000_000,
        40_000_000_000,
    ];

    /** @var list<int> */
    public const GLOBAL_MILESTONES = [
        100_000_000,
        500_000_000,
        1_000_000_000,
        2_000_000_000,
    ];

    /**
     * @param  list<array<string, mixed>>  $currentMovies
     * @param  array<string, list<array<string, mixed>>>  $observationsByKey
     * @param  array<string, array<string, mixed>>  $registryMovies
     * @return array{
     *     movies: array<string, array<string, mixed>>,
     *     board: list<array<string, mixed>>,
     *     today: list<array<string, mixed>>,
     *     milestones: list<array<string, mixed>>
     * }
     */
    public static function compute(
        string $region,
        array $currentMovies,
        array $observationsByKey,
        array $registryMovies,
        DateTimeImmutable $now,
    ): array {
        $currentByKey = [];
        foreach ($currentMovies as $movie) {
            $currentByKey[$movie['key']] = $movie;
        }

        $rankedNow = self::ranksFromSnapshot(array_map(
            fn (array $movie) => ['key' => $movie['key'], 'boxOffice' => (int) $movie['boxOffice']],
            $currentMovies,
        ));

        $weekAgo = $now->sub(new DateInterval('P7D'));
        $rankedThen = self::ranksFromSnapshot(self::snapshotAt($currentByKey, $observationsByKey, $weekAgo));

        $movies = [];
        $board = [];
        $today = [];
        $milestones = [];

        foreach ($currentMovies as $movie) {
            self::collectInsight(
                $region,
                $movie,
                $observationsByKey[$movie['key']] ?? [],
                $registryMovies[$movie['key']] ?? [],
                $rankedNow[$movie['key']] ?? null,
                $rankedThen[$movie['key']] ?? null,
                $currentByKey,
                $now,
                $movies,
                $board,
                $today,
                $milestones,
            );
        }

        foreach ($observationsByKey as $key => $rows) {
            if (isset($movies[$key])) {
                continue;
            }
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            if ($last === null) {
                continue;
            }
            $registry = $registryMovies[$key] ?? [];
            self::collectInsight(
                $region,
                [
                    'key' => $key,
                    'title' => $registry['title'] ?? $key,
                    'boxOffice' => (int) ($last['boxOffice'] ?? 0),
                    'isActive' => (bool) ($last['isActive'] ?? false),
                    'releaseDate' => $registry['releaseDate'] ?? null,
                    'releaseDatePrecision' => $registry['releaseDatePrecision'] ?? null,
                ],
                $rows,
                $registry,
                null,
                $rankedThen[$key] ?? null,
                $currentByKey,
                $now,
                $movies,
                $board,
                $today,
                $milestones,
            );
        }

        usort($board, function (array $a, array $b) {
            $deltaA = $a['delta'] ?? -1;
            $deltaB = $b['delta'] ?? -1;
            if ($deltaA === $deltaB) {
                return $b['boxOffice'] <=> $a['boxOffice'];
            }

            return $deltaB <=> $deltaA;
        });

        usort($today, fn (array $a, array $b) => strcmp($b['lastObservedAt'] ?? '', $a['lastObservedAt'] ?? ''));
        usort($milestones, fn (array $a, array $b) => strcmp($b['reachedAt'] ?? '', $a['reachedAt'] ?? ''));

        return [
            'movies' => $movies,
            'board' => $board,
            'today' => array_slice($today, 0, 20),
            'milestones' => array_slice($milestones, 0, 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, mixed>  $registry
     * @param  array<string, array<string, mixed>>  $currentByKey
     * @param  array<string, array<string, mixed>>  $movies
     * @param  list<array<string, mixed>>  $board
     * @param  list<array<string, mixed>>  $today
     * @param  list<array<string, mixed>>  $milestones
     */
    private static function collectInsight(
        string $region,
        array $movie,
        array $observations,
        array $registry,
        ?int $rankNow,
        ?int $rankThen,
        array $currentByKey,
        DateTimeImmutable $now,
        array &$movies,
        array &$board,
        array &$today,
        array &$milestones,
    ): void {
        $insight = self::forMovie(
            $region,
            $movie,
            $observations,
            $registry,
            $rankNow,
            $rankThen,
            $currentByKey,
            $now,
        );
        $movies[$movie['key']] = $insight;

        if ($insight['onBoard']) {
            $board[] = $insight;
        }
        if ($insight['changedRecently']) {
            $today[] = $insight;
        }
        foreach ($insight['recentMilestones'] as $milestone) {
            $milestones[] = $milestone;
        }
    }

    /**
     * @param  array<string, mixed>  $movie
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, mixed>  $registry
     * @param  array<string, array<string, mixed>>  $currentByKey
     * @return array<string, mixed>
     */
    public static function forMovie(
        string $region,
        array $movie,
        array $observations,
        array $registry,
        ?int $rankNow,
        ?int $rankThen,
        array $currentByKey,
        DateTimeImmutable $now,
    ): array {
        $boxOffice = (int) $movie['boxOffice'];
        $isJapan = $region === 'japan';
        $growth = self::growth($observations);
        $last = $observations === [] ? null : $observations[array_key_last($observations)];
        $lastObservedAt = $last['observedAt'] ?? null;
        $precision = $registry['releaseDatePrecision'] ?? ($movie['releaseDatePrecision'] ?? null);
        $releaseDate = $registry['releaseDate'] ?? ($movie['releaseDate'] ?? null);
        $daysSinceRelease = self::daysSinceRelease($releaseDate, $precision, $now);
        $lastChangeAt = self::lastChangeAt($observations);
        $isActive = (bool) ($movie['isActive'] ?? false);
        $onBoard = $isActive;
        $changedRecently = $isActive
            && $lastChangeAt !== null
            && self::hoursBetween(new DateTimeImmutable($lastChangeAt), $now) <= self::TODAY_WITHIN_HOURS
            && count($observations) > 1;

        $milestones = self::milestones($region, $movie, $observations, $releaseDate, $precision);
        $recentMilestones = array_values(array_filter(
            $milestones,
            fn (array $item) => isset($item['reachedAt'])
                && self::hoursBetween(new DateTimeImmutable($item['reachedAt']), $now) <= (self::RECENT_MILESTONE_WITHIN_DAYS * 24),
        ));

        $passed = [];
        if (($growth['previousBoxOffice'] ?? null) !== null && ($growth['delta'] ?? 0) > 0) {
            $passed = self::passedMovies($movie['key'], (int) $growth['previousBoxOffice'], $boxOffice, $currentByKey);
        }

        $rankDelta = ($rankNow !== null && $rankThen !== null) ? $rankThen - $rankNow : null;

        return [
            'key' => $movie['key'],
            'title' => $movie['title'],
            'region' => $region,
            'boxOffice' => $boxOffice,
            'revenueLabel' => self::formatAmount($boxOffice, $isJapan),
            'isActive' => $isActive,
            'rank' => $rankNow ?? ($movie['rank'] ?? null),
            'rankBefore' => $rankThen,
            'rankDelta' => $rankDelta,
            'rankDeltaLabel' => self::rankDeltaLabel($rankDelta),
            'delta' => $growth['delta'],
            'deltaLabel' => $growth['delta'] === null ? null : self::formatDelta($growth['delta'], $isJapan),
            'daysSincePrev' => $growth['days'],
            'previousObservedAt' => $growth['previousObservedAt'],
            'dailyPace' => $growth['dailyPace'],
            'dailyPaceLabel' => $growth['dailyPace'] === null ? null : '1日'.self::formatAmount((int) round($growth['dailyPace']), $isJapan),
            'lastObservedAt' => $lastObservedAt,
            'lastChangeAt' => $lastChangeAt,
            'daysSinceRelease' => $daysSinceRelease,
            'releaseDate' => $releaseDate,
            'releaseDatePrecision' => $precision,
            'onBoard' => $onBoard,
            'changedRecently' => $changedRecently,
            'passed' => $passed,
            'passedLabel' => $passed === [] ? null : self::passedLabel($passed),
            'milestones' => $milestones,
            'recentMilestones' => $recentMilestones,
            'nextMilestone' => self::nextMilestone($region, $boxOffice, $isJapan),
            'nextToOvertake' => $isActive
                ? self::nextToOvertake($movie['key'], $boxOffice, $currentByKey, $isJapan)
                : null,
            'periodGrowth' => self::periodGrowth($observations, $boxOffice, $now, $isJapan),
            'sparkline' => self::sparkline($observations),
            'hasHistory' => count($observations) > 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return list<array{days: int, delta: int, label: string}>
     */
    public static function periodGrowth(
        array $observations,
        int $current,
        DateTimeImmutable $now,
        bool $isJapan,
    ): array {
        $periods = [];
        foreach (self::PERIOD_LABELS as $days => $prefix) {
            $then = $now->sub(new DateInterval('P'.$days.'D'));
            $previous = self::boxOfficeAt($observations, $then);
            if ($previous === null) {
                continue;
            }
            $delta = $current - $previous;
            if ($delta <= 0) {
                continue;
            }
            $periods[] = [
                'days' => $days,
                'delta' => $delta,
                'label' => $prefix.self::formatDelta($delta, $isJapan),
            ];
        }

        return $periods;
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return array{delta: int|null, days: int|null, dailyPace: float|null, previousBoxOffice: int|null, previousObservedAt: string|null}
     */
    public static function growth(array $observations): array
    {
        $empty = [
            'delta' => null,
            'days' => null,
            'dailyPace' => null,
            'previousBoxOffice' => null,
            'previousObservedAt' => null,
        ];
        if (count($observations) < 2) {
            return $empty;
        }

        $current = $observations[array_key_last($observations)];
        $previous = null;
        for ($i = count($observations) - 2; $i >= 0; $i--) {
            if (! empty($observations[$i]['correction'])) {
                continue;
            }
            $previous = $observations[$i];
            break;
        }

        if ($previous === null || ! empty($current['correction'])) {
            return $empty;
        }

        $delta = (int) $current['boxOffice'] - (int) $previous['boxOffice'];
        if ($delta <= 0) {
            return $empty;
        }

        $days = max(1, (int) floor(self::hoursBetween(
            new DateTimeImmutable($previous['observedAt']),
            new DateTimeImmutable($current['observedAt']),
        ) / 24));

        return [
            'delta' => $delta,
            'days' => $days,
            'dailyPace' => $delta / $days,
            'previousBoxOffice' => (int) $previous['boxOffice'],
            'previousObservedAt' => $previous['observedAt'],
        ];
    }

    /**
     * @param  list<array{key: string, boxOffice: int}>  $snapshot
     * @return array<string, int>
     */
    public static function ranksFromSnapshot(array $snapshot): array
    {
        usort($snapshot, fn (array $a, array $b) => $b['boxOffice'] <=> $a['boxOffice']);
        $ranks = [];
        $rank = 1;
        foreach ($snapshot as $row) {
            $ranks[$row['key']] = $rank++;
        }

        return $ranks;
    }

    /**
     * @param  array<string, array<string, mixed>>  $currentByKey
     * @param  array<string, list<array<string, mixed>>>  $observationsByKey
     * @return list<array{key: string, boxOffice: int}>
     */
    public static function snapshotAt(array $currentByKey, array $observationsByKey, DateTimeImmutable $at): array
    {
        $snapshot = [];
        foreach ($currentByKey as $key => $movie) {
            $value = self::boxOfficeAt($observationsByKey[$key] ?? [], $at);
            if ($value === null) {
                continue;
            }
            $snapshot[] = ['key' => $key, 'boxOffice' => $value];
        }

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     */
    public static function boxOfficeAt(array $observations, DateTimeImmutable $at): ?int
    {
        $latest = null;
        foreach ($observations as $row) {
            try {
                $observed = new DateTimeImmutable($row['observedAt']);
            } catch (\Exception) {
                continue;
            }
            if ($observed > $at) {
                continue;
            }
            $latest = (int) $row['boxOffice'];
        }

        return $latest;
    }

    public static function formatAmount(int $amount, bool $isJapan): string
    {
        $oku = $amount / 100_000_000;
        if ($isJapan) {
            return number_format($oku, 1).'億円';
        }

        return number_format($oku, 2).'億ドル';
    }

    public static function formatDelta(int $amount, bool $isJapan): string
    {
        return '+'.self::formatAmount($amount, $isJapan);
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @param  array<string, mixed>  $movie
     * @return list<array<string, mixed>>
     */
    private static function milestones(
        string $region,
        array $movie,
        array $observations,
        ?string $releaseDate,
        ?string $precision,
    ): array {
        $thresholds = $region === 'japan' ? self::JAPAN_MILESTONES : self::GLOBAL_MILESTONES;
        $isJapan = $region === 'japan';
        $reached = [];
        $rows = array_values(array_filter($observations, fn (array $row) => empty($row['correction'])));

        foreach ($thresholds as $threshold) {
            // 「閾値未満 → 以上」の横断を実際に観測できた場合のみ到達扱い。
            // 初回観測時点で既に超えていた過去の到達は、時期不明なので記録しない。
            $hit = null;
            $seenBelow = false;
            foreach ($rows as $row) {
                if ((int) $row['boxOffice'] < $threshold) {
                    $seenBelow = true;
                    continue;
                }
                if ($seenBelow) {
                    $hit = $row;
                }
                break;
            }
            if ($hit === null) {
                continue;
            }
            $days = self::daysSinceRelease($releaseDate, $precision, new DateTimeImmutable($hit['observedAt']));
            $reached[] = [
                'key' => $movie['key'],
                'title' => $movie['title'],
                'threshold' => $threshold,
                'label' => self::formatAmount($threshold, $isJapan).'突破',
                'reachedAt' => $hit['observedAt'],
                'daysToReach' => $days,
            ];
        }

        return $reached;
    }

    /**
     * @return array{threshold: int, label: string, remaining: int, remainingLabel: string}|null
     */
    public static function nextMilestone(string $region, int $boxOffice, bool $isJapan): ?array
    {
        $thresholds = $region === 'japan' ? self::JAPAN_MILESTONES : self::GLOBAL_MILESTONES;
        foreach ($thresholds as $threshold) {
            if ($threshold > $boxOffice) {
                $remaining = $threshold - $boxOffice;

                return [
                    'threshold' => $threshold,
                    'label' => self::formatAmount($threshold, $isJapan),
                    'remaining' => $remaining,
                    'remainingLabel' => self::formatAmount($remaining, $isJapan),
                ];
            }
        }

        return null;
    }

    /**
     * Immediate next film above this one on the current ranking snapshot.
     *
     * @param  array<string, array<string, mixed>>  $currentByKey
     * @return array{key: string, title: string, rank: int, remaining: int, remainingLabel: string}|null
     */
    public static function nextToOvertake(string $key, int $boxOffice, array $currentByKey, bool $isJapan): ?array
    {
        $bestKey = null;
        $bestValue = null;

        foreach ($currentByKey as $otherKey => $other) {
            if ($otherKey === $key) {
                continue;
            }
            $value = (int) ($other['boxOffice'] ?? 0);
            if ($value <= $boxOffice) {
                continue;
            }
            if ($bestValue === null || $value < $bestValue) {
                $bestKey = $otherKey;
                $bestValue = $value;
            }
        }

        if ($bestKey === null || $bestValue === null) {
            return null;
        }

        $rank = 1;
        foreach ($currentByKey as $otherKey => $other) {
            if ($otherKey === $bestKey) {
                continue;
            }
            if ((int) ($other['boxOffice'] ?? 0) > $bestValue) {
                $rank++;
            }
        }

        $remaining = $bestValue - $boxOffice;

        return [
            'key' => $bestKey,
            'title' => (string) ($currentByKey[$bestKey]['title'] ?? $bestKey),
            'rank' => $rank,
            'remaining' => $remaining,
            'remainingLabel' => self::formatAmount($remaining, $isJapan),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $currentByKey
     * @return list<array{key: string, title: string}>
     */
    private static function passedMovies(string $key, int $previous, int $current, array $currentByKey): array
    {
        $passed = [];
        foreach ($currentByKey as $otherKey => $other) {
            if ($otherKey === $key) {
                continue;
            }
            $value = (int) $other['boxOffice'];
            if ($value > $previous && $value <= $current) {
                $passed[] = [
                    'key' => $otherKey,
                    'title' => $other['title'],
                ];
            }
        }

        return $passed;
    }

    /**
     * @param  list<array{key: string, title: string}>  $passed
     */
    private static function passedLabel(array $passed): string
    {
        $names = array_map(fn (array $item) => '『'.$item['title'].'』', array_slice($passed, 0, 2));
        $label = implode('と', $names).'を抜いた';
        if (count($passed) > 2) {
            $label .= ' ほか';
        }

        return $label;
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     * @return list<array{at: string, boxOffice: int}>
     */
    private static function sparkline(array $observations): array
    {
        $points = [];
        foreach ($observations as $row) {
            if (! empty($row['correction'])) {
                continue;
            }
            $points[] = [
                'at' => $row['observedAt'],
                'boxOffice' => (int) $row['boxOffice'],
            ];
        }

        return array_slice($points, -16);
    }

    /**
     * @param  list<array<string, mixed>>  $observations
     */
    private static function lastChangeAt(array $observations): ?string
    {
        // 修正（correction）は「発表があった」とは扱わない
        $rows = array_values(array_filter($observations, fn (array $row) => empty($row['correction'])));
        if (count($rows) < 2) {
            return null;
        }

        for ($i = count($rows) - 1; $i >= 1; $i--) {
            if ((int) $rows[$i]['boxOffice'] !== (int) $rows[$i - 1]['boxOffice']) {
                return $rows[$i]['observedAt'] ?? null;
            }
        }

        return null;
    }

    private static function daysSinceRelease(?string $releaseDate, ?string $precision, DateTimeImmutable $at): ?int
    {
        if (! $releaseDate || $precision !== 'day') {
            return null;
        }

        try {
            $released = new DateTimeImmutable($releaseDate);
        } catch (\Exception) {
            return null;
        }

        $days = (int) floor(($at->getTimestamp() - $released->getTimestamp()) / 86400);

        return max(0, $days);
    }

    private static function hoursBetween(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        return ($to->getTimestamp() - $from->getTimestamp()) / 3600;
    }

    private static function rankDeltaLabel(?int $rankDelta): ?string
    {
        if ($rankDelta === null || $rankDelta === 0) {
            return null;
        }

        return $rankDelta > 0 ? $rankDelta.'位上昇' : abs($rankDelta).'位下降';
    }
}
