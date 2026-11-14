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
        $title = self::displayTitle($title);
        $title = self::norm($title);
        $title = preg_replace('/[とのをはがへで]/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', '', $title) ?? $title;

        return $title;
    }

    /**
     * Wikipediaの曖昧さ回避「(2002年の映画)」などを表示用タイトルから外す。
     */
    public static function displayTitle(string $title): string
    {
        $title = preg_replace('/\s*[（(][^（）()]{0,40}の映画[）)]/u', '', $title) ?? $title;
        $title = preg_replace('/\s*[（(]映画[）)]/u', '', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    public static function isNonFilmRowTitle(string $title): bool
    {
        $title = JapanBoxOfficeAmount::normalize($title);
        if ($title === '') {
            return false;
        }
        if (preg_match('/Box Office Mojo|The Numbers|Deadline|Variety|Rotten Tomatoes|Metacritic|\bIMDb\b/iu', $title)) {
            return true;
        }
        if (preg_match('/(?:^|[\s　])(合計|総計|小計|計)$/u', $title) || preg_match('/^(合計|総計|小計|計|total)$/iu', $title)) {
            return true;
        }
        if (preg_match('/^(監督|脚本|音楽|主題歌|歌手|公開日|興行収入|配給収入|通番|シリーズ|備考|記録|内容|出典)$/u', $title)) {
            return true;
        }
        if (preg_match('/^備考\b/u', $title) || preg_match('/インフレーション調整/u', $title)) {
            return true;
        }
        if (preg_match('/連続TOP|連続1位|歴代最高|観客動員数$/u', $title)) {
            return true;
        }

        return strlen($title) < 2;
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
    /**
     * 同一作品か。TMDB id、または正規化タイトルが一致し公開年が空か1年以内。
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function isSameFilm(array $a, array $b): bool
    {
        $tmdbA = self::tmdbId($a);
        $tmdbB = self::tmdbId($b);
        if ($tmdbA !== null && $tmdbB !== null) {
            return $tmdbA === $tmdbB;
        }

        $titleA = self::identityCompact((string) ($a['title'] ?? ''));
        $titleB = self::identityCompact((string) ($b['title'] ?? ''));
        if ($titleA === '' || $titleB === '' || $titleA !== $titleB) {
            return false;
        }

        $yearA = self::year($a);
        $yearB = self::year($b);
        if ($yearA !== null && $yearB !== null && abs($yearA - $yearB) > 1) {
            return false;
        }

        return true;
    }

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
