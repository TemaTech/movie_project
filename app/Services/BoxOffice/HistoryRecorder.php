<?php

namespace App\Services\BoxOffice;

use DateTimeImmutable;
use DateTimeInterface;

class HistoryRecorder
{
    public const MINIMUM_MOVIES = 150;

    public function __construct(
        private readonly Registry $registry,
        private readonly ObservationStore $observations,
    ) {
    }

    public static function fromBasePath(string $historyDir): self
    {
        return new self(
            new Registry(rtrim($historyDir, '/').'/registry.json'),
            new ObservationStore(rtrim($historyDir, '/').'/observations'),
        );
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    public function observations(): ObservationStore
    {
        return $this->observations;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function resolve(array $incoming): array
    {
        return $this->registry->resolve($incoming);
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @return list<string>
     */
    public function recordSnapshot(string $region, array $movies, DateTimeInterface $observedAt): array
    {
        $iso = DateTimeImmutable::createFromInterface($observedAt)->format('c');
        $existing = $this->observations->loadByKey($region);
        $currentKeys = [];
        $warnings = [];

        foreach ($movies as $movie) {
            $key = (string) $movie['movie_id'];
            $currentKeys[$key] = true;
            $row = [
                'key' => $key,
                'observedAt' => $iso,
                'boxOffice' => (int) $movie['box_office'],
                'isActive' => (bool) ($movie['is_active'] ?? false),
            ];
            if ((int) $movie['box_office'] < (int) ($this->observations->lastForKey($existing[$key] ?? [])['boxOffice'] ?? 0)) {
                $row['correction'] = true;
            }
            $previous = $this->observations->lastForKey($existing[$key] ?? []);
            if ($this->observations->shouldRecord($row, $previous)) {
                $this->observations->append($region, $row);
                $existing[$key][] = $row;
            }
        }

        foreach ($existing as $key => $rows) {
            $last = $this->observations->lastForKey($rows);
            if ($last && ! empty($last['isActive']) && ! isset($currentKeys[$key])) {
                $title = $this->registry->get($key)['title'] ?? $key;
                $warnings[] = "公開中だった『{$title}』({$key}) が今回の取得結果から消えました。";
                $closing = [
                    'key' => $key,
                    'observedAt' => $iso,
                    'boxOffice' => (int) ($last['boxOffice'] ?? 0),
                    'isActive' => false,
                ];
                if ($this->observations->shouldRecord($closing, $last)) {
                    $this->observations->append($region, $closing);
                    $existing[$key][] = $closing;
                }
            }
        }

        $this->registry->save();

        return $warnings;
    }

    public function save(): void
    {
        $this->registry->save();
    }
}
