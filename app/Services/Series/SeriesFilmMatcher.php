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
        $candidates = self::filmKeys($film);
        $best = null;
        $bestScore = 0.0;

        foreach ($movies as $movie) {
            $score = 0.0;
            foreach (self::movieKeys($movie) as $key) {
                foreach ($candidates as $candidate) {
                    if ($key === $candidate) {
                        $score = 1.0;
                        break 2;
                    }
                }
            }

            if ($score < 1.0) {
                $title = self::norm((string) ($movie['title'] ?? ''));
                foreach ($candidates as $candidate) {
                    similar_text($title, $candidate, $percent);
                    $score = max($score, $percent / 100);
                }
            }

            $filmYear = $film['releaseYear'] ?? null;
            $movieYear = $movie['releaseYear'] ?? (isset($movie['releaseDate']) ? (int) substr((string) $movie['releaseDate'], 0, 4) : null);
            if ($filmYear && $movieYear && abs((int) $filmYear - (int) $movieYear) > 1) {
                $score -= 0.25;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $movie;
            }
        }

        return $bestScore >= 0.82 ? $best : null;
    }

    /**
     * Ranking-only fallback when Wikipedia had no per-film table.
     *
     * @param  array<string, mixed>  $series
     * @param  list<array<string, mixed>>  $movies
     * @param  array<string, true>  $claimedIds
     * @return list<array<string, mixed>>
     */
    public static function rankingMembers(array $series, array $movies, array $claimedIds): array
    {
        $name = self::norm((string) ($series['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $members = [];
        foreach ($movies as $movie) {
            $id = (string) ($movie['id'] ?? '');
            if ($id === '' || isset($claimedIds[$id])) {
                continue;
            }
            $title = self::norm((string) ($movie['title'] ?? ''));
            if ($title === $name || str_starts_with($title, $name)) {
                $members[] = $movie;
            }
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $film
     * @return list<string>
     */
    public static function filmKeys(array $film): array
    {
        $keys = [];
        foreach (['title', 'shortTitle', 'wikipediaTitle'] as $field) {
            $value = self::norm((string) ($film[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return list<string>
     */
    public static function movieKeys(array $movie): array
    {
        $keys = [];
        foreach (['title', 'originalTitle'] as $field) {
            $value = self::norm((string) ($movie[$field] ?? ''));
            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }

    public static function norm(string $title): string
    {
        $title = MovieIdentity::normalizeTitle($title);
        $title = preg_replace('/^劇場版\s*/u', '', $title) ?? $title;
        $title = str_replace(['シリーズ'], '', $title);

        return trim($title);
    }
}
