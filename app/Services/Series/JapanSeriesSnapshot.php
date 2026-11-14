<?php

namespace App\Services\Series;

use Illuminate\Support\Facades\File;

class JapanSeriesSnapshot
{
    public static function path(): string
    {
        return base_path('data/series/japan.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $path = self::path();
        if (! File::exists($path)) {
            return [
                'sourceUrl' => JapanSeriesWikipediaParser::SOURCE_URL,
                'sourcePage' => JapanSeriesWikipediaParser::SOURCE_PAGE,
                'sourceSection' => JapanSeriesWikipediaParser::SOURCE_SECTION,
                'retrievedAt' => null,
                'license' => 'CC BY-SA 4.0',
                'series' => [],
                'warnings' => [],
                'unparsedSeries' => [],
            ];
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function save(array $payload): void
    {
        File::ensureDirectoryExists(dirname(self::path()));
        File::put(
            self::path(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function series(): array
    {
        $series = self::load()['series'] ?? [];

        return is_array($series) ? array_values($series) : [];
    }
}
