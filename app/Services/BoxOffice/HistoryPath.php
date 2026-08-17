<?php

namespace App\Services\BoxOffice;

class HistoryPath
{
    public static function canonical(): string
    {
        return base_path('data/history');
    }

    public static function resolve(): string
    {
        $configured = function_exists('config') ? config('box_office.history_path') : null;
        $path = $configured
            ? self::normalizePath($configured)
            : self::canonical();

        if ($path !== self::canonical() && ! is_dir($path)) {
            self::bootstrapFrom($path);
        }

        return $path;
    }

    public static function bootstrapFrom(string $target, ?string $source = null): void
    {
        $source ??= self::canonical();

        if (is_dir($source)) {
            self::copyDirectory($source, $target);

            return;
        }

        self::ensureDirectory($target.'/observations/global');
        self::ensureDirectory($target.'/observations/japan');

        if (! is_file($target.'/registry.json')) {
            file_put_contents($target.'/registry.json', "{\"movies\":{},\"aliases\":{}}\n");
        }
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }

        return base_path($path);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    private static function copyDirectory(string $from, string $to): void
    {
        self::ensureDirectory($to);
        $items = scandir($from) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fromPath = $from.'/'.$item;
            $toPath = $to.'/'.$item;
            if (is_dir($fromPath)) {
                self::copyDirectory($fromPath, $toPath);
            } else {
                copy($fromPath, $toPath);
            }
        }
    }
}
