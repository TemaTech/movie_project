<?php

namespace App\Console\Commands;

use App\Services\Site\InternalLinkChecker;
use Illuminate\Console\Command;

class CheckInternalLinks extends Command
{
    protected $signature = 'site:check-internal-links
                            {--output=dist : Static site directory}';

    protected $description = 'Warn about broken internal href="/..." links in exported HTML (does not fail the build)';

    public function handle(): int
    {
        $raw = (string) $this->option('output');
        $output = is_dir($raw) ? rtrim($raw, '/') : base_path($raw);

        if (! is_dir($output)) {
            $this->warn("Output directory not found: {$output}");

            return self::SUCCESS;
        }

        $result = InternalLinkChecker::scan($output);
        $this->info("Scanned {$result['scanned']} HTML files, {$result['links']} internal links ({$result['unique']} unique).");

        if ($result['missing'] === []) {
            $this->info('No broken internal links.');

            return self::SUCCESS;
        }

        $this->warn(count($result['missing']).' broken internal link(s) (warning only; build continues):');
        foreach ($result['missing'] as $row) {
            $this->line('  '.$row['href'].'  (from '.$row['from'].')');
        }

        return self::SUCCESS;
    }
}
