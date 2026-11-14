<?php

namespace App\Services\Series;

class SeriesSlug
{
    /** @var list<string> */
    private const GENERIC_SLUGS = [
        'collection',
        'series',
        'saga',
        'franchise',
        'コレクション',
        'シリーズ',
        '系列',
        'global',
    ];

    public static function fromName(string $name, ?int $collectionId = null): string
    {
        $name = trim($name);
        if ($name === '') {
            return self::fallback($name, $collectionId);
        }

        $ascii = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $ascii = trim($ascii, '-');
        $ascii = preg_replace('/-+/', '-', $ascii) ?? $ascii;

        $nonAscii = preg_replace('/[a-zA-Z0-9]+/u', '', $name) ?? $name;
        $nonAscii = preg_replace('/[（）()「」『』【】\[\]\/／~〜・：:\s\-]+/u', '', $nonAscii) ?? $nonAscii;

        $useAscii = strlen($ascii) >= 3 && preg_match('/[a-z]/', $ascii) && $nonAscii === '';

        if ($useAscii) {
            $slug = strlen($ascii) > 80 ? rtrim(substr($ascii, 0, 80), '-') : $ascii;
        } else {
            $slug = preg_replace('/[（）()「」『』【】\[\]\/／~〜]+/u', '', $name) ?? $name;
            $slug = str_replace(['・', '：', ':'], '', $slug);
            $slug = preg_replace('/\s+/u', '-', $slug) ?? $slug;
            $slug = trim($slug, '-');
        }

        if (self::isGeneric($slug)) {
            return self::fallback($name, $collectionId);
        }

        if (mb_strlen($slug) > 80) {
            $slug = mb_substr($slug, 0, 80);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    /**
     * @param  list<string>  $used
     */
    public static function unique(string $name, array $used, ?int $collectionId = null): string
    {
        $base = self::fromName($name, $collectionId);
        if (! in_array($base, $used, true)) {
            return $base;
        }

        if ($collectionId !== null && $collectionId > 0) {
            $fallback = self::collectionFallback($collectionId);
            if (! in_array($fallback, $used, true)) {
                return $fallback;
            }
        }

        for ($i = 2; $i < 50; $i++) {
            $candidate = $base.'-'.$i;
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return $base.'-'.substr(hash('sha256', $name.(string) $collectionId), 0, 6);
    }

    public static function isGeneric(string $slug): bool
    {
        $slug = trim($slug);

        return $slug === '' || in_array(mb_strtolower($slug), self::GENERIC_SLUGS, true);
    }

    public static function collectionFallback(int $collectionId): string
    {
        return 'tmdb-collection-'.$collectionId;
    }

    private static function fallback(string $name, ?int $collectionId): string
    {
        if ($collectionId !== null && $collectionId > 0) {
            return self::collectionFallback($collectionId);
        }

        return 's-'.substr(hash('sha256', $name === '' ? 'empty' : $name), 0, 10);
    }
}
