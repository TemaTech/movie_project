<?php

namespace App\Services\Series;

class JapanBoxOfficeAmount
{
    public const METRIC_BOX_OFFICE = 'box_office';

    public const METRIC_DISTRIBUTOR_RENTALS = 'distributor_rentals';

    /**
     * @return array{yen: int, metric: string}|null
     */
    public static function parse(string $text, ?string $preferredMetric = null): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '' || self::isMissing($normalized)) {
            return null;
        }

        if (self::looksWorldwide($normalized)) {
            return null;
        }

        $metric = $preferredMetric;
        if ($metric === null) {
            if (str_contains($normalized, '配給収入') || str_contains($normalized, '配収')) {
                $metric = self::METRIC_DISTRIBUTOR_RENTALS;
            } else {
                $metric = self::METRIC_BOX_OFFICE;
            }
        }

        $yen = self::parseYen($normalized);

        return $yen === null ? null : [
            'yen' => $yen,
            'metric' => $metric,
        ];
    }

    public static function looksWorldwide(string $text): bool
    {
        $text = self::normalize($text);

        return (bool) preg_match('/\$|USD|US\$|億ドル|百万ドル|million|billion/i', $text);
    }

    public static function isMissing(string $text): bool
    {
        $text = self::normalize($text);

        return $text === ''
            || $text === '-'
            || $text === '—'
            || $text === '–'
            || str_contains($text, '未発表')
            || str_contains($text, '不明')
            || str_contains($text, 'なし');
    }

    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<ref\b[^>]*>.*?<\/ref>/su', '', $text) ?? $text;
        $text = preg_replace('/\{\{Efn2[\s\S]*?\}\}/u', '', $text) ?? $text;
        $text = preg_replace('/\{\{Color\|[^|]+\|([^}]+)\}\}/u', '$1', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/u', ' ', $text) ?? $text;
        $text = preg_replace('/\[\d+\]/u', '', $text) ?? $text;
        $text = preg_replace('/注\s*\d+/u', '', $text) ?? $text;
        $text = str_replace(["\xc2\xa0", '　'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function parseYen(string $text): ?int
    {
        $text = self::normalize($text);
        $text = str_replace(',', '', $text);

        if (preg_match('/(\d+(?:\.\d+)?)\s*億\s*(\d+(?:\.\d+)?)\s*万/u', $text, $matches)) {
            return (int) round(((float) $matches[1] * 10000 + (float) $matches[2]) * 10000);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*億円/u', $text, $matches)) {
            return (int) round((float) $matches[1] * 100000000);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*億/u', $text, $matches) && ! str_contains($text, 'ドル')) {
            return (int) round((float) $matches[1] * 100000000);
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*万円/u', $text, $matches)) {
            return (int) round((float) $matches[1] * 10000);
        }

        return null;
    }
}
