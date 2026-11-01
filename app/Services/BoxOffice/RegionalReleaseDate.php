<?php

namespace App\Services\BoxOffice;

/**
 * 日本ページの公開日は、チャートの「公開◯日」と同じ日本公開日を使う。
 * TMDB 詳細の release_date は世界公開日なので、日精度の日本公開日があるときだけ差し替える。
 */
class RegionalReleaseDate
{
    public static function displayed(?string $stored, ?string $precision, ?string $tmdb, bool $isJapan): ?string
    {
        if ($isJapan && self::hasDayPrecision($stored, $precision)) {
            return $stored;
        }

        return $tmdb ?? $stored;
    }

    public static function hasDayPrecision(?string $date, ?string $precision): bool
    {
        if (! is_string($date) || $date === '') {
            return false;
        }

        return $precision === null || $precision === 'day';
    }
}
