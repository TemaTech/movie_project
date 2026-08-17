<?php

namespace Tests\Feature\BoxOffice;

use App\Services\BoxOffice\HistoryRecorder;
use Tests\TestCase;

class HistoryRecorderConfigTest extends TestCase
{
    public function test_from_config_uses_configured_history_path(): void
    {
        $local = storage_path('framework/testing/box-office-history-'.bin2hex(random_bytes(4)));
        mkdir($local.'/observations/japan', 0777, true);
        file_put_contents($local.'/registry.json', '{"movies":{},"aliases":{}}');

        config(['box_office.driver' => 'file', 'box_office.history_path' => $local]);

        $recorder = HistoryRecorder::fromConfig();
        $recorder->resolve([
            'region' => 'japan',
            'title' => '設定パステスト',
            'tmdbId' => 42,
            'releaseYear' => 2026,
        ]);
        $recorder->save();

        $this->assertFileExists($local.'/registry.json');
        $this->assertStringContainsString('設定パステスト', (string) file_get_contents($local.'/registry.json'));

        $this->removeDir($local);
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
