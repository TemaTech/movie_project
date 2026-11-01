<?php

namespace App\Services\BoxOffice;

/**
 * 日本ページの公開日は、チャートの「公開◯日」と同じ日本公開日を使う。
 * TMDB 詳細の release_date は世界公開日なので、日精度の日本公開日があるときだけ差し替える。
 */
class RegionalReleaseDate
{
    /**
     * チャートと同じ公開日。pageHistory は git の registry.json 由来。
     *
     * @param  array<string, mixed>  $movie
     * @return array{0: ?string, 1: ?string}
     */
    public static function stored(array $movie): array
    {
        $history = is_array($movie['pageHistory'] ?? null) ? $movie['pageHistory'] : [];
        if (array_key_exists('releaseDate', $history)) {
            return [
                self::stringOrNull($history['releaseDate'] ?? null),
                self::stringOrNull($history['releaseDatePrecision'] ?? null),
            ];
        }

        return [
            self::stringOrNull($movie['releaseDate'] ?? null),
            self::stringOrNull($movie['releaseDatePrecision'] ?? null),
        ];
    }

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

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
