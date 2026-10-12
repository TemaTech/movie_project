<?php

namespace App\Services\BoxOffice;

use DateTimeImmutable;

/**
 * 初回公開から半年を過ぎた作品でも、劇場リバイバル中なら公開中として扱う。
 * ただし過去作品は、そのリバイバル開始後に累計興収が目に見えて増えたときだけ対象にする。
 * 興収が動かない再上映日や、まだ始まっていない劇場公開日では公開中にしない。
 */
class TheatricalRun
{
    public const WINDOW_MONTHS = 6;

    /** TMDB release type: 2 = Theatrical (limited), 3 = Theatrical */
    private const THEATRICAL_TYPES = [2, 3];

    /**
     * @param  list<array<string, mixed>>  $releaseDateResults  TMDB /movie/{id}/release_dates の results
     * @param  list<array<string, mixed>>  $observations
     * @return array{active: bool, revivalDate: ?string}
     */
    public static function assess(
        ?string $primaryReleaseDate,
        array $releaseDateResults,
        DateTimeImmutable $now,
        array $observations = [],
        ?int $currentBoxOffice = null,
    ): array {
        $cutoff = self::cutoffDate($now);
        $primaryActive = self::primaryIsCurrent($primaryReleaseDate, $cutoff);
        $revivalDate = null;

        if (! $primaryActive && $currentBoxOffice !== null) {
            $candidate = self::latestTheatricalDate($releaseDateResults, $cutoff, $now->format('Y-m-d'));
            if ($candidate !== null && self::boxOfficeAdvancedSince($candidate, $observations, $currentBoxOffice)) {
                $revivalDate = $candidate;
            }
        }

        return [
            'active' => $primaryActive || $revivalDate !== null,
            'revivalDate' => $revivalDate,
        ];
    }

    /**
     * 取得済みの公開日だけで、いま公開中として残してよいかを決める。
     *
     * @param  list<array<string, mixed>>  $observations
     */
    public static function storedRunIsCurrent(
        ?string $primaryReleaseDate,
        ?string $revivalDate,
        array $observations,
        int $currentBoxOffice,
        DateTimeImmutable $now,
    ): bool {
        if (self::primaryIsCurrent($primaryReleaseDate, self::cutoffDate($now))) {
            return true;
        }

        return self::confirmedRevivalDate($primaryReleaseDate, $revivalDate, $observations, $currentBoxOffice, $now) !== null;
    }

    /**
     * チャートの起点に使ってよいリバイバル日。条件を満たさなければ null。
     *
     * @param  list<array<string, mixed>>  $observations
     */
    public static function confirmedRevivalDate(
        ?string $primaryReleaseDate,
        ?string $revivalDate,
        array $observations,
        int $currentBoxOffice,
        DateTimeImmutable $now,
    ): ?string {
        if (self::primaryIsCurrent($primaryReleaseDate, self::cutoffDate($now))) {
            return null;
        }
        if (! is_string($revivalDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $revivalDate)) {
            return null;
        }

        $cutoff = self::cutoffDate($now);
        $today = $now->format('Y-m-d');
        if ($revivalDate < $cutoff || $revivalDate > $today) {
            return null;
        }
        if (! self::boxOfficeAdvancedSince($revivalDate, $observations, $currentBoxOffice)) {
            return null;
        }

        return $revivalDate;
    }

    private static function cutoffDate(DateTimeImmutable $now): string
    {
        return $now->modify('-'.self::WINDOW_MONTHS.' months')->format('Y-m-d');
    }

    private static function primaryIsCurrent(?string $primaryReleaseDate, string $cutoff): bool
    {
        return is_string($primaryReleaseDate) && $primaryReleaseDate >= $cutoff;
    }

    /**
     * リバイバル開始前の累計より、いまの累計が画面上の単位で増えているか。
     * 開始前の記録がなければ、開始後の記録どうしで増えているかを見る。
     *
     * @param  list<array<string, mixed>>  $observations
     */
    private static function boxOfficeAdvancedSince(string $revivalDate, array $observations, int $currentBoxOffice): bool
    {
        $baseline = null;
        $baselineAt = null;
        $lowestDuring = null;

        foreach ($observations as $row) {
            if (! is_array($row)) {
                continue;
            }
            $stamp = (string) ($row['observedAt'] ?? '');
            $day = substr($stamp, 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $amount = (int) ($row['boxOffice'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($day < $revivalDate) {
                if ($baselineAt === null || $stamp >= $baselineAt) {
                    $baseline = $amount;
                    $baselineAt = $stamp;
                }

                continue;
            }
            if ($lowestDuring === null || $amount < $lowestDuring) {
                $lowestDuring = $amount;
            }
        }

        if ($baseline !== null) {
            return self::visiblyIncreased($baseline, $currentBoxOffice);
        }
        if ($lowestDuring === null) {
            return false;
        }

        return self::visiblyIncreased($lowestDuring, $currentBoxOffice);
    }

    private static function visiblyIncreased(int $from, int $to): bool
    {
        return $to > $from
            && Insights::formatAmount($from, false) !== Insights::formatAmount($to, false);
    }

    /**
     * @param  list<array<string, mixed>>  $releaseDateResults
     */
    private static function latestTheatricalDate(array $releaseDateResults, string $cutoff, string $until): ?string
    {
        $latest = null;

        foreach ($releaseDateResults as $country) {
            if (! is_array($country)) {
                continue;
            }
            foreach ($country['release_dates'] ?? [] as $release) {
                if (! is_array($release) || ! in_array((int) ($release['type'] ?? 0), self::THEATRICAL_TYPES, true)) {
                    continue;
                }
                $date = substr((string) ($release['release_date'] ?? ''), 0, 10);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                if ($date < $cutoff || $date > $until) {
                    continue;
                }
                if ($latest === null || $date > $latest) {
                    $latest = $date;
                }
            }
        }

        return $latest;
    }
}
