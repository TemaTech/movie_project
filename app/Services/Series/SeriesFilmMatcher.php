<?php

namespace App\Services\Series;

use App\Services\BoxOffice\MovieIdentity;

class SeriesFilmMatcher
{
    /**
     * @param  array<string, mixed>  $film
     * @param  list<array<string, mixed>>  $movies
     * @return array<string, mixed>|null
     */
    public static function match(array $film, array $movies): ?array
    {
        $result = self::matchAll($film, $movies);
        if ($result['ambiguous'] !== [] || count($result['matches']) !== 1) {
            return null;
        }

        return $result['matches'][0];
    }

    /**
     * 厳密一致のみ。類似度フォールバックやタイトル接頭辞の曖昧マッチはしない。
     *
     * @param  array<string, mixed>  $film
     * @param  list<array<string, mixed>>  $movies
     * @return array{matches: list<array<string, mixed>>, ambiguous: list<string>}
     */
    public static function matchAll(array $film, array $movies): array
    {
        $filmKeys = self::compactKeys($film);
        if ($filmKeys === []) {
            return ['matches' => [], 'ambiguous' => []];
        }

        $filmYear = self::year($film);
        $matches = [];
        foreach ($movies as $movie) {
            $movieKeys = self::compactKeys($movie);
            if ($movieKeys === []) {
                continue;
            }
            if (array_intersect($filmKeys, $movieKeys) === []) {
                continue;
            }
            $movieYear = self::year($movie);
            if ($filmYear && $movieYear && abs($filmYear - $movieYear) > 1) {
                continue;
            }
            $matches[] = $movie;
        }

        if (count($matches) > 1) {
            return [
                'matches' => [],
                'ambiguous' => array_values(array_map(fn (array $movie) => (string) ($movie['title'] ?? $movie['id'] ?? ''), $matches)),
            ];
        }

        return ['matches' => $matches, 'ambiguous' => []];
    }

    /**
     * @param  array<string, mixed>  $film
     * @return list<string>
     */
    public static function compactKeys(array $film): array
    {
        $keys = [];
        foreach (['title', 'shortTitle', 'wikipediaTitle', 'originalTitle'] as $field) {
            $value = self::compact((string) ($film[$field] ?? ''));
            if ($value !== '' && mb_strlen($value) >= 2) {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $film
     * @return list<string>
     */
    public static function filmKeys(array $film): array
    {
        return self::compactKeys($film);
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return list<string>
     */
    public static function movieKeys(array $movie): array
    {
        return self::compactKeys($movie);
    }

    public static function norm(string $title): string
    {
        $title = MovieIdentity::normalizeTitle($title);
        $title = preg_replace('/^劇場版\s*/u', '', $title) ?? $title;
        $title = str_replace(['シリーズ'], '', $title);

        return trim($title);
    }

    public static function compact(string $title): string
    {
        $title = self::norm($title);
        $title = preg_replace('/[とのをはがへで]/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', '', $title) ?? $title;

        return $title;
    }

    /**
     * 重複判定用。括弧付き「(映画)」や末尾の「映画」を落としてから compact する。
     */
    public static function identityCompact(string $title): string
    {
        $compact = self::compact($title);
        $compact = preg_replace('/(?:映画|劇場版)+$/u', '', $compact) ?? $compact;

        return $compact;
    }

    /**
     * @param  array<string, mixed>  $film
     */
    public static function tmdbId(array $film): ?int
    {
        if (is_numeric($film['tmdbId'] ?? null) && (int) $film['tmdbId'] > 0) {
            return (int) $film['tmdbId'];
        }
        $fromKey = MovieIdentity::tmdbIdFromKey((string) ($film['id'] ?? ''));

        return $fromKey !== null && $fromKey > 0 ? $fromKey : null;
    }

    /**
     * TMDB id、なければ正規化タイトル+公開年。
     *
     * @param  array<string, mixed>  $film
     * @return list<string>
     */
    public static function dedupeKeys(array $film): array
    {
        $keys = [];
        $tmdbId = self::tmdbId($film);
        if ($tmdbId !== null) {
            $keys[] = 'tmdb:'.$tmdbId;
        }

        $year = self::year($film);
        $titles = [];
        foreach (['title', 'wikipediaTitle', 'originalTitle'] as $field) {
            $value = self::identityCompact((string) ($film[$field] ?? ''));
            if ($value !== '' && mb_strlen($value) >= 4) {
                $titles[$value] = $value;
            }
        }
        if ($titles !== [] && $year !== null) {
            foreach ($titles as $title) {
                $keys[] = 'title:'.$title.'|'.$year;
            }
        }

        return array_values($keys);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function year(array $item): ?int
    {
        if (is_numeric($item['releaseYear'] ?? null)) {
            return (int) $item['releaseYear'];
        }
        $date = $item['releaseDate'] ?? null;
        if (is_string($date) && preg_match('/^((?:19|20)\d{2})/', $date, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
