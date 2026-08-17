<?php

namespace Tests\Unit\BoxOffice;

use App\Services\BoxOffice\HistoryPath;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\BoxOffice\Insights;
use App\Services\BoxOffice\MovieIdentity;
use App\Services\BoxOffice\ObservationStore;
use App\Services\BoxOffice\Registry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoxOfficeHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/boxoffice-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/observations/japan', 0777, true);
        mkdir($this->dir.'/observations/global', 0777, true);
        file_put_contents($this->dir.'/registry.json', '{"movies":{},"aliases":{}}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function test_identity_keys_are_stable_and_independent_of_rank(): void
    {
        $normalized = MovieIdentity::normalizeTitle('君の名は。');
        $this->assertSame('jp-tmdb-372058', MovieIdentity::japanKey(372058, $normalized, 2016));
        $this->assertSame(
            MovieIdentity::japanKey(null, $normalized, 2016),
            MovieIdentity::japanKey(null, $normalized, 2016),
        );
        $this->assertSame('tmdb-19995', MovieIdentity::globalKey(19995));
        $this->assertNotSame(
            MovieIdentity::japanLegacyId(12, '君の名は。', '東宝', '2016-01-01'),
            MovieIdentity::japanLegacyId(5, '君の名は。', '東宝', '2016-01-01'),
        );
    }

    public function test_registry_reuses_tmdb_match_when_title_changes(): void
    {
        $registry = new Registry($this->dir.'/registry.json');
        $first = $registry->resolve([
            'region' => 'japan',
            'title' => '君の名は。',
            'tmdbId' => 372058,
            'releaseYear' => 2016,
            'legacyIds' => ['jp_012_aaaaaaaa'],
        ]);
        $second = $registry->resolve([
            'region' => 'japan',
            'title' => '君の名は',
            'tmdbId' => 372058,
            'releaseYear' => 2016,
            'legacyIds' => ['jp_008_bbbbbbbb'],
        ]);

        $this->assertSame($first['key'], $second['key']);
        $this->assertContains('jp_012_aaaaaaaa', $second['legacyIds']);
        $this->assertContains('jp_008_bbbbbbbb', $second['legacyIds']);
    }

    public function test_registry_matches_title_and_nearby_year_without_tmdb(): void
    {
        $registry = new Registry($this->dir.'/registry.json');
        $first = $registry->resolve([
            'region' => 'japan',
            'title' => 'テスト映画',
            'releaseYear' => 2024,
        ]);
        $second = $registry->resolve([
            'region' => 'japan',
            'title' => 'テスト映画',
            'releaseYear' => 2025,
        ]);

        $this->assertSame($first['key'], $second['key']);
        $this->assertStringStartsWith('jp-', $first['key']);
        $this->assertStringStartsNotWith('jp-tmdb-', $first['key']);
    }

    public function test_observations_skip_unchanged_values_and_flag_corrections(): void
    {
        $recorder = HistoryRecorder::fromBasePath($this->dir);
        $movie = $this->japanMovie('jp-tmdb-1', '作品A', 5_000_000_000, true);
        $recorder->resolve([
            'region' => 'japan',
            'title' => '作品A',
            'tmdbId' => 1,
            'releaseYear' => 2024,
            'legacyIds' => ['jp_001_oldhash'],
        ]);

        $recorder->recordSnapshot('japan', [$movie], new DateTimeImmutable('2026-08-01T03:00:00+09:00'));
        $recorder->recordSnapshot('japan', [$movie], new DateTimeImmutable('2026-08-01T09:00:00+09:00'));

        $grown = $this->japanMovie('jp-tmdb-1', '作品A', 5_800_000_000, true);
        $recorder->recordSnapshot('japan', [$grown], new DateTimeImmutable('2026-08-07T03:00:00+09:00'));

        $corrected = $this->japanMovie('jp-tmdb-1', '作品A', 5_700_000_000, true);
        $recorder->recordSnapshot('japan', [$corrected], new DateTimeImmutable('2026-08-08T03:00:00+09:00'));

        $rows = (new ObservationStore($this->dir.'/observations'))->loadByKey('japan')['jp-tmdb-1'];
        $this->assertCount(3, $rows);
        $this->assertTrue($rows[2]['correction']);
        $this->assertSame(5_000_000_000, $rows[0]['boxOffice']);
        $this->assertSame(5_800_000_000, $rows[1]['boxOffice']);
    }

    public function test_insights_compute_pace_rank_pass_and_board_membership(): void
    {
        $now = new DateTimeImmutable('2026-08-14T12:00:00+09:00');
        $observations = [
            'jp-a' => [
                $this->obs('jp-a', '2026-08-01T03:00:00+09:00', 4_000_000_000, true),
                $this->obs('jp-a', '2026-08-08T03:00:00+09:00', 5_200_000_000, true),
            ],
            'jp-b' => [
                $this->obs('jp-b', '2026-07-01T03:00:00+09:00', 4_500_000_000, false),
                $this->obs('jp-b', '2026-07-10T03:00:00+09:00', 4_600_000_000, false),
            ],
            'jp-c' => [
                $this->obs('jp-c', '2026-07-01T03:00:00+09:00', 8_000_000_000, false),
            ],
            'jp-d' => [
                $this->obs('jp-d', '2026-07-20T03:00:00+09:00', 3_000_000_000, true),
                $this->obs('jp-d', '2026-08-05T03:00:00+09:00', 3_400_000_000, true),
            ],
        ];

        $current = [
            $this->current('jp-a', '伸びてる映画', 5_200_000_000, true),
            $this->current('jp-b', '抜かれた映画', 4_600_000_000, false),
            $this->current('jp-c', '不動の上位', 8_000_000_000, false),
        ];

        $result = Insights::compute('japan', $current, $observations, [
            'jp-a' => ['releaseDate' => '2026-06-20', 'releaseDatePrecision' => 'day'],
        ], $now);

        $this->assertTrue($result['movies']['jp-a']['onBoard']);
        $this->assertFalse($result['movies']['jp-c']['onBoard']);
        $this->assertSame(1_200_000_000, $result['movies']['jp-a']['delta']);
        $this->assertSame('+12.0億円', $result['movies']['jp-a']['deltaLabel']);
        $this->assertSame(7, $result['movies']['jp-a']['daysSincePrev']);
        $this->assertSame('jp-b', $result['movies']['jp-a']['passed'][0]['key']);
        $this->assertSame(2, $result['movies']['jp-a']['rank']);
        $this->assertNotEmpty($result['movies']['jp-a']['milestones']);
        $this->assertSame('jp-a', $result['board'][0]['key']);
        $this->assertTrue($result['movies']['jp-d']['onBoard']);
        $this->assertContains('jp-d', array_column($result['board'], 'key'));
    }

    public function test_negative_or_correction_deltas_are_hidden(): void
    {
        $observations = [
            $this->obs('jp-a', '2026-08-01T03:00:00+09:00', 5_000_000_000, true),
            $this->obs('jp-a', '2026-08-08T03:00:00+09:00', 4_800_000_000, true, true),
        ];
        $growth = Insights::growth($observations);
        $this->assertNull($growth['delta']);
    }

    public function test_redirects_are_emitted_from_legacy_ids(): void
    {
        $registry = new Registry($this->dir.'/registry.json');
        $registry->resolve([
            'region' => 'global',
            'title' => 'Avatar',
            'tmdbId' => 19995,
            'releaseYear' => 2009,
            'legacyIds' => ['global_001_19995'],
        ]);
        $rules = $registry->redirects();
        $this->assertSame('/movies/001_19995', $rules[0]['from']);
        $this->assertSame('/movies/tmdb-19995/', $rules[0]['to']);
    }

    public function test_disappeared_active_movie_is_closed_in_history(): void
    {
        $recorder = HistoryRecorder::fromBasePath($this->dir);
        $recorder->resolve([
            'region' => 'japan',
            'title' => '公開終了映画',
            'tmdbId' => 9,
            'releaseYear' => 2026,
        ]);
        $movie = $this->japanMovie('jp-tmdb-9', '公開終了映画', 2_000_000_000, true);
        $recorder->recordSnapshot('japan', [$movie], new DateTimeImmutable('2026-08-01T03:00:00+09:00'));
        $warnings = $recorder->recordSnapshot('japan', [], new DateTimeImmutable('2026-08-08T03:00:00+09:00'));

        $rows = (new ObservationStore($this->dir.'/observations'))->loadByKey('japan')['jp-tmdb-9'];
        $this->assertNotEmpty($warnings);
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[1]['isActive']);
        $this->assertSame(2_000_000_000, $rows[1]['boxOffice']);
    }

    public function test_local_history_path_bootstraps_from_canonical_directory(): void
    {
        $canonical = sys_get_temp_dir().'/boxoffice-canonical-'.bin2hex(random_bytes(4));
        $local = sys_get_temp_dir().'/boxoffice-local-'.bin2hex(random_bytes(4));
        mkdir($canonical.'/observations/japan', 0777, true);
        file_put_contents($canonical.'/registry.json', '{"movies":{"jp-a":{"key":"jp-a"}},"aliases":{}}');
        file_put_contents(
            $canonical.'/observations/japan/2026-08.ndjson',
            json_encode(['key' => 'jp-a', 'observedAt' => '2026-08-01T03:00:00+09:00', 'boxOffice' => 1, 'isActive' => true])."\n"
        );

        HistoryPath::bootstrapFrom($local, $canonical);

        $this->assertFileExists($local.'/registry.json');
        $this->assertFileExists($local.'/observations/japan/2026-08.ndjson');
        $this->assertStringContainsString('jp-a', (string) file_get_contents($local.'/registry.json'));

        $this->removeDir($canonical);
        $this->removeDir($local);
    }

    public function test_stable_keys_can_be_parsed_from_identity(): void
    {
        $this->assertSame(19995, MovieIdentity::tmdbIdFromKey('tmdb-19995'));
        $this->assertSame(372058, MovieIdentity::tmdbIdFromKey('jp-tmdb-372058'));
        $this->assertNull(MovieIdentity::tmdbIdFromKey('jp-abcdef1234'));
        $this->assertSame(
            'aaaaaaaa',
            MovieIdentity::japanHashFromLegacyId('jp_012_aaaaaaaa')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function japanMovie(string $key, string $title, int $boxOffice, bool $isActive): array
    {
        return [
            'movie_id' => $key,
            'title' => $title,
            'box_office' => $boxOffice,
            'is_active' => $isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function current(string $key, string $title, int $boxOffice, bool $isActive): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'boxOffice' => $boxOffice,
            'isActive' => $isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function obs(string $key, string $at, int $boxOffice, bool $isActive, bool $correction = false): array
    {
        $row = [
            'key' => $key,
            'observedAt' => $at,
            'boxOffice' => $boxOffice,
            'isActive' => $isActive,
        ];
        if ($correction) {
            $row['correction'] = true;
        }

        return $row;
    }

    private function removeDir(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
