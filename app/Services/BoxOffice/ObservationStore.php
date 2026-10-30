<?php

namespace App\Services\BoxOffice;

use App\Services\BoxOffice\Contracts\ObservationRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class ObservationStore implements ObservationRepository
{
    public function __construct(private readonly string $directory) {}

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
            $byKey[$key] = $this->collapseDuplicates($rows, $region === 'japan');
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
    public function shouldRecord(array $current, ?array $previous, bool $isJapan = false): bool
    {
        if ($previous === null) {
            return true;
        }

        if ((bool) ($previous['isActive'] ?? false) !== (bool) ($current['isActive'] ?? false)) {
            return true;
        }

        return $this->boxOfficeVisiblyChanged(
            (int) ($previous['boxOffice'] ?? 0),
            (int) ($current['boxOffice'] ?? 0),
            $isJapan,
        );
    }

    public function compact(string $region): int
    {
        $pattern = rtrim($this->directory, '/').'/'.$region.'/*.ndjson';
        $files = glob($pattern) ?: [];
        $removed = 0;
        foreach ($files as $file) {
            $removed += $this->compactFile($file);
        }

        return $removed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function keepLatestPerDate(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $row) {
            $day = self::calendarDate((string) ($row['observedAt'] ?? $row['at'] ?? ''));
            if ($day === null) {
                $byDay['?'.count($byDay)] = $row;

                continue;
            }
            $previous = $byDay[$day] ?? null;
            if ($previous === null || self::isLaterObservation($row, $previous)) {
                $byDay[$day] = $row;
            }
        }

        return array_values($byDay);
    }

    public static function calendarDate(string $observedAt): ?string
    {
        if ($observedAt === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($observedAt))
                ->setTimezone(new DateTimeZone('Asia/Tokyo'))
                ->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function collapseDuplicates(array $rows, bool $isJapan): array
    {
        $collapsed = [];
        foreach (self::keepLatestPerDate($rows) as $row) {
            $previous = $collapsed === [] ? null : $collapsed[array_key_last($collapsed)];
            if ($previous
                && (bool) ($previous['isActive'] ?? false) === (bool) ($row['isActive'] ?? false)
                && ! $this->boxOfficeVisiblyChanged(
                    (int) ($previous['boxOffice'] ?? 0),
                    (int) ($row['boxOffice'] ?? 0),
                    $isJapan,
                )
            ) {
                continue;
            }
            $collapsed[] = $row;
        }

        return $collapsed;
    }

    private function compactFile(string $path): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        $rows = [];
        $latestIndex = [];
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $row = json_decode($trimmed, true);
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            $index = count($rows);
            $rows[] = $row;
            $day = self::calendarDate((string) ($row['observedAt'] ?? '')) ?? (string) ($row['observedAt'] ?? $index);
            $id = $row['key']."\0".$day;
            $previous = $latestIndex[$id] ?? null;
            if ($previous === null || self::isLaterObservation($row, $rows[$previous])) {
                $latestIndex[$id] = $index;
            }
        }
        fclose($handle);

        $keep = array_fill(0, count($rows), false);
        foreach ($latestIndex as $index) {
            $keep[$index] = true;
        }

        $kept = [];
        foreach ($rows as $index => $row) {
            if ($keep[$index]) {
                $kept[] = $row;
            }
        }

        $removed = count($rows) - count($kept);
        if ($removed <= 0) {
            return 0;
        }

        $buffer = '';
        foreach ($kept as $row) {
            $buffer .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        }
        file_put_contents($path, $buffer, LOCK_EX);

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private static function isLaterObservation(array $candidate, array $current): bool
    {
        $candidateAt = (string) ($candidate['observedAt'] ?? $candidate['at'] ?? '');
        $currentAt = (string) ($current['observedAt'] ?? $current['at'] ?? '');
        if ($candidateAt !== $currentAt) {
            return $candidateAt > $currentAt;
        }

        return (int) ($candidate['boxOffice'] ?? 0) > (int) ($current['boxOffice'] ?? 0);
    }

    private function boxOfficeVisiblyChanged(int $from, int $to, bool $isJapan): bool
    {
        return Insights::formatAmount($from, $isJapan) !== Insights::formatAmount($to, $isJapan);
    }

    private function monthPath(string $region, DateTimeInterface $observedAt): string
    {
        $tokyo = DateTimeImmutable::createFromInterface($observedAt)->setTimezone(new DateTimeZone('Asia/Tokyo'));

        return rtrim($this->directory, '/').'/'.$region.'/'.$tokyo->format('Y-m').'.ndjson';
    }
}
