<?php

namespace App\Services\Series;

class SeriesSlug
{
    public static function fromName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 's-'.substr(hash('sha256', 'empty'), 0, 10);
        }

        $ascii = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $ascii = trim($ascii, '-');
        $ascii = preg_replace('/-+/', '-', $ascii) ?? $ascii;
        if (strlen($ascii) >= 3 && preg_match('/[a-z]/', $ascii)) {
            return strlen($ascii) > 80 ? rtrim(substr($ascii, 0, 80), '-') : $ascii;
        }

        $slug = preg_replace('/[（）()「」『』【】\[\]\/／~〜]+/u', '', $name) ?? $name;
        $slug = str_replace(['・', '：', ':'], '', $slug);
        $slug = preg_replace('/\s+/u', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        if ($slug === '' || mb_strlen($slug) < 1) {
            return 's-'.substr(hash('sha256', $name), 0, 10);
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
    public static function unique(string $name, array $used): string
    {
        $base = self::fromName($name);
        if (! in_array($base, $used, true)) {
            return $base;
        }

        for ($i = 2; $i < 50; $i++) {
            $candidate = $base.'-'.$i;
            if (! in_array($candidate, $used, true)) {
                return $candidate;
            }
        }

        return $base.'-'.substr(hash('sha256', $name), 0, 6);
    }
}
