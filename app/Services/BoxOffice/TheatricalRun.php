<?php

namespace App\Services\BoxOffice;

use DateTimeImmutable;

/**
 * 初回公開から半年を過ぎた作品でも、劇場リバイバル中なら公開中として扱う。
 */
class TheatricalRun
{
    public const WINDOW_MONTHS = 6;

    /** TMDB release type: 2 = Theatrical (limited), 3 = Theatrical */
    private const THEATRICAL_TYPES = [2, 3];

    /**
     * @param  list<array<string, mixed>>  $releaseDateResults  TMDB /movie/{id}/release_dates の results
     * @return array{active: bool, revivalDate: ?string}
     */
    public static function assess(?string $primaryReleaseDate, array $releaseDateResults, DateTimeImmutable $now): array
    {
        $cutoff = $now->modify('-'.self::WINDOW_MONTHS.' months')->format('Y-m-d');
        $horizon = $now->modify('+'.self::WINDOW_MONTHS.' months')->format('Y-m-d');
        $primaryActive = is_string($primaryReleaseDate) && $primaryReleaseDate >= $cutoff;

        $revivalDate = null;
        if (! $primaryActive) {
            $revivalDate = self::latestTheatricalDate($releaseDateResults, $cutoff, $horizon);
        }

        return [
            'active' => $primaryActive || $revivalDate !== null,
            'revivalDate' => $revivalDate,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $releaseDateResults
     */
    private static function latestTheatricalDate(array $releaseDateResults, string $cutoff, string $horizon): ?string
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
                if ($date < $cutoff || $date > $horizon) {
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
