<?php

namespace App\Services\BoxOffice;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class ObservationStore
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param  array<string, mixed>  $observation
     */
    public function append(string $region, array $observation): bool
    {
        $observedAt = new DateTimeImmutable($observation['observedAt']);
        $path = $this->monthPath($region, $observedAt);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $line = json_encode($observation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);

        return true;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function loadByKey(string $region): array
    {
        $pattern = rtrim($this->directory, '/').'/'.$region.'/*.ndjson';
        $files = glob($pattern) ?: [];
        sort($files);

        $byKey = [];
        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (! is_array($row) || empty($row['key'])) {
                    continue;
                }
                $byKey[$row['key']][] = $row;
            }
            fclose($handle);
        }

        foreach ($byKey as $key => $rows) {
            usort($rows, fn (array $a, array $b) => strcmp($a['observedAt'] ?? '', $b['observedAt'] ?? ''));
            $byKey[$key] = $this->collapseDuplicates($rows);
        }

        return $byKey;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function lastForKey(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        return $rows[array_key_last($rows)];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $previous
     */
    public function shouldRecord(array $current, ?array $previous): bool
    {
        if ($previous === null) {
            return true;
        }

        return (int) ($previous['boxOffice'] ?? 0) !== (int) ($current['boxOffice'] ?? 0)
            || (bool) ($previous['isActive'] ?? false) !== (bool) ($current['isActive'] ?? false);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function collapseDuplicates(array $rows): array
    {
        $collapsed = [];
        foreach ($rows as $row) {
            $previous = $collapsed === [] ? null : $collapsed[array_key_last($collapsed)];
            if ($previous
                && (int) ($previous['boxOffice'] ?? 0) === (int) ($row['boxOffice'] ?? 0)
                && (bool) ($previous['isActive'] ?? false) === (bool) ($row['isActive'] ?? false)
                && empty($row['correction'])
            ) {
                continue;
            }
            $collapsed[] = $row;
        }

        return $collapsed;
    }

    private function monthPath(string $region, DateTimeInterface $observedAt): string
    {
        $tokyo = DateTimeImmutable::createFromInterface($observedAt)->setTimezone(new DateTimeZone('Asia/Tokyo'));

        return rtrim($this->directory, '/').'/'.$region.'/'.$tokyo->format('Y-m').'.ndjson';
    }
}
