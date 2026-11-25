<?php

namespace App\Services\Site;

class InternalLinkChecker
{
    /**
     * @return array{
     *     scanned: int,
     *     links: int,
     *     unique: int,
     *     missing: list<array{href: string, from: string}>
     * }
     */
    public static function scan(string $outputDir): array
    {
        $outputDir = rtrim($outputDir, '/');
        $htmlFiles = self::htmlFiles($outputDir);
        $seen = [];
        $missing = [];
        $linkCount = 0;

        foreach ($htmlFiles as $file) {
            $html = (string) file_get_contents($file);
            foreach (self::internalHrefs($html) as $href) {
                $linkCount++;
                $key = $href."\n".$file;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (self::targetExists($outputDir, $href)) {
                    continue;
                }
                $missing[] = [
                    'href' => $href,
                    'from' => self::relativePath($outputDir, $file),
                ];
            }
        }

        $uniqueHrefs = [];
        foreach ($seen as $key => $_) {
            $href = explode("\n", $key, 2)[0];
            $uniqueHrefs[$href] = true;
        }

        $uniqueMissing = [];
        $deduped = [];
        foreach ($missing as $row) {
            if (isset($uniqueMissing[$row['href']])) {
                continue;
            }
            $uniqueMissing[$row['href']] = true;
            $deduped[] = $row;
        }

        return [
            'scanned' => count($htmlFiles),
            'links' => $linkCount,
            'unique' => count($uniqueHrefs),
            'missing' => $deduped,
        ];
    }

    public static function targetExists(string $outputDir, string $href): bool
    {
        $outputDir = rtrim($outputDir, '/');
        $path = self::pathFromHref($href);
        if ($path === null) {
            return true;
        }
        if ($path === '/') {
            return is_file($outputDir.'/index.html');
        }

        $relative = ltrim($path, '/');
        $trimmed = rtrim($relative, '/');
        foreach ([
            $outputDir.'/'.$relative,
            $outputDir.'/'.$trimmed,
            $outputDir.'/'.$trimmed.'/index.html',
            $outputDir.'/'.$trimmed.'.html',
        ] as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
            if (is_dir($candidate) && is_file($candidate.'/index.html')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function internalHrefs(string $html): array
    {
        if (preg_match_all('/\bhref\s*=\s*(["\'])(\/[^"\']*)\1/i', $html, $matches) < 1) {
            return [];
        }

        $hrefs = [];
        foreach ($matches[2] as $href) {
            if (str_starts_with($href, '//')) {
                continue;
            }
            $path = self::pathFromHref($href);
            if ($path === null) {
                continue;
            }
            $hrefs[] = $path;
        }

        return $hrefs;
    }

    public static function pathFromHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//')) {
            return null;
        }

        $path = parse_url($href, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $decoded = rawurldecode($path);
        if ($decoded === '') {
            return null;
        }

        return str_starts_with($decoded, '/') ? $decoded : '/'.$decoded;
    }

    /**
     * @return list<string>
     */
    private static function htmlFiles(string $outputDir): array
    {
        if (! is_dir($outputDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outputDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'html') {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private static function relativePath(string $outputDir, string $file): string
    {
        $prefix = rtrim($outputDir, '/').'/';
        if (str_starts_with($file, $prefix)) {
            return substr($file, strlen($prefix));
        }

        return $file;
    }
}
