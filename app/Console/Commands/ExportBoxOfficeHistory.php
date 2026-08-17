<?php

namespace App\Console\Commands;

use App\Services\BoxOffice\HistoryPath;
use App\Services\BoxOffice\HistoryRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportBoxOfficeHistory extends Command
{
    protected $signature = 'box-office:export-history
                            {--output= : Directory to write the export bundle}
                            {--path= : History directory to export (defaults to configured path)}';

    protected $description = 'Export box office history in a migration-ready bundle format';

    public function handle(): int
    {
        $historyDir = $this->option('path')
            ? rtrim((string) $this->option('path'), '/')
            : HistoryPath::resolve();

        if (! is_dir($historyDir)) {
            $this->error("History directory not found: {$historyDir}");

            return self::FAILURE;
        }

        $output = $this->option('output')
            ? rtrim((string) $this->option('output'), '/')
            : storage_path('app/box-office-history-export/'.now('Asia/Tokyo')->format('Y-m-d_His'));

        File::deleteDirectory($output);
        File::ensureDirectoryExists($output.'/observations/global');
        File::ensureDirectoryExists($output.'/observations/japan');

        $registryPath = $historyDir.'/registry.json';
        if (! is_file($registryPath)) {
            $this->error("Registry file not found: {$registryPath}");

            return self::FAILURE;
        }

        File::copy($registryPath, $output.'/registry.json');
        $this->copyObservations($historyDir.'/observations/global', $output.'/observations/global');
        $this->copyObservations($historyDir.'/observations/japan', $output.'/observations/japan');

        $regions = [];
        foreach (['global', 'japan'] as $region) {
            $files = glob($output.'/observations/'.$region.'/*.ndjson') ?: [];
            if ($files !== []) {
                $regions[] = $region;
            }
        }

        File::put($output.'/manifest.json', json_encode([
            'format' => 'box-office-history',
            'formatVersion' => 1,
            'exportedAt' => now('Asia/Tokyo')->toIso8601String(),
            'sourceDriver' => config('box_office.driver', 'file'),
            'sourcePath' => $historyDir,
            'regions' => $regions,
            'files' => [
                'registry' => 'registry.json',
                'observations' => 'observations/{region}/{YYYY-MM}.ndjson',
            ],
            'observationSchema' => [
                'key' => 'string',
                'observedAt' => 'ISO-8601 datetime',
                'boxOffice' => 'integer (yen)',
                'isActive' => 'boolean',
                'correction' => 'boolean (optional)',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        $recorder = HistoryRecorder::fromBasePath($historyDir);
        $movieCount = count($recorder->registry()->movies());
        $observationCount = 0;
        foreach ($regions as $region) {
            foreach ($recorder->observations()->loadByKey($region) as $rows) {
                $observationCount += count($rows);
            }
        }

        $this->info("Exported box office history to {$output}");
        $this->line("Movies in registry: {$movieCount}");
        $this->line("Observation rows: {$observationCount}");
        $this->line('Regions: '.implode(', ', $regions));

        return self::SUCCESS;
    }

    private function copyObservations(string $from, string $to): void
    {
        if (! is_dir($from)) {
            return;
        }

        foreach (glob($from.'/*.ndjson') ?: [] as $file) {
            File::copy($file, $to.'/'.basename($file));
        }
    }
}
