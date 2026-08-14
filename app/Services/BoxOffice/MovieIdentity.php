<?php

namespace App\Services\BoxOffice;

class MovieIdentity
{
    public static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = mb_convert_kana($title, 'as');
        $title = str_replace(['：', '﹕', '︰'], ':', $title);
        $title = str_replace(['＆', '﹠'], '&', $title);
        $title = str_replace(['Ⅱ', 'ii', 'II'], '2', $title);
        $title = preg_replace('/[「」『』【】\[\]（）()・\/／\-–—_]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title);

        return trim((string) $title);
    }

    public static function japanKey(?int $tmdbId, string $normalizedTitle, ?int $year): string
    {
        if ($tmdbId) {
            return 'jp-tmdb-'.$tmdbId;
        }

        return 'jp-'.substr(hash('sha256', $normalizedTitle.'|'.($year ?? '')), 0, 10);
    }

    public static function globalKey(int $tmdbId): string
    {
        return 'tmdb-'.$tmdbId;
    }

    public static function japanLegacyId(int $rank, string $title, string $distributor, string $wikipediaYearDate): string
    {
        return 'jp_'.sprintf('%03d', $rank).'_'.substr(md5($title.'|'.$distributor.'|'.$wikipediaYearDate), 0, 8);
    }

    public static function globalLegacyId(int $rank, int $tmdbId): string
    {
        return 'global_'.sprintf('%03d', $rank).'_'.$tmdbId;
    }

    public static function globalLegacySlug(int $rank, int $tmdbId): string
    {
        return sprintf('%03d', $rank).'_'.$tmdbId;
    }

    public static function regionFromKey(string $key): string
    {
        return str_starts_with($key, 'jp-') || str_starts_with($key, 'jp_') ? 'japan' : 'global';
    }

    public static function tmdbIdFromKey(string $key): ?int
    {
        if (preg_match('/^(?:jp-)?tmdb-(\d+)$/', $key, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function japanHashFromLegacyId(string $legacyId): ?string
    {
        if (preg_match('/^jp_\d{3}_([0-9a-f]{8})$/', $legacyId, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
