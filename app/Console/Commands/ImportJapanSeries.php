<?php

namespace App\Console\Commands;

use App\Services\Series\JapanSeriesSnapshot;
use App\Services\Series\JapanSeriesWikipediaParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportJapanSeries extends Command
{
    protected $signature = 'series:import-japan
                            {--dry-run : Parse and report without writing the snapshot}';

    protected $description = 'Wikipedia『映画シリーズの興行成績』から日本シリーズ興収のスナップショットを作る';

    public function handle(): int
    {
        $parser = new JapanSeriesWikipediaParser;
        $this->info('Wikipediaのシリーズ一覧を取得します...');

        $indexJson = $this->fetchParse(JapanSeriesWikipediaParser::SOURCE_PAGE, 'wikitext');
        $wikitext = $indexJson['parse']['wikitext']['*'] ?? '';
        if ($wikitext === '') {
            $this->error('シリーズ一覧のwikitextを取得できませんでした');

            return self::FAILURE;
        }

        $series = $parser->parseSeriesIndex($wikitext);
        if ($series === []) {
            $this->error('映画シリーズの興行成績テーブルを解析できませんでした');

            return self::FAILURE;
        }

        $this->info(sprintf('シリーズ %d 件を検出しました。各ページの作品表を取得します。', count($series)));

        $warnings = [];
        $unparsed = [];
        $filmCount = 0;

        foreach ($series as $index => &$entry) {
            $page = (string) ($entry['wikipediaTitle'] ?? '');
            $this->line(sprintf('[%d/%d] %s', $index + 1, count($series), $entry['name']));
            usleep(500000);

            try {
                $htmlJson = $this->fetchParse($page, 'text');
                $html = $htmlJson['parse']['text']['*'] ?? '';
            } catch (\Throwable $e) {
                $unparsed[] = [
                    'name' => $entry['name'],
                    'reason' => 'page_fetch_failed',
                    'detail' => $e->getMessage(),
                ];
                $warnings[] = $entry['name'].': ページ取得失敗';

                continue;
            }

            $parsed = $parser->parseSeriesPageHtml($html, $entry);
            $entry['films'] = $parsed['films'];
            $filmCount += count($parsed['films']);
            foreach ($parsed['warnings'] as $warning) {
                $warnings[] = $entry['name'].': '.$warning;
            }

            $theatrical = array_values(array_filter(
                $parsed['films'],
                fn (array $film) => ($film['kind'] ?? '') === 'theatrical'
            ));
            if ($theatrical === [] && $parsed['films'] === []) {
                $unparsed[] = [
                    'name' => $entry['name'],
                    'reason' => 'no_film_table',
                    'wikipediaTitle' => $page,
                ];
            }

            $missing = array_values(array_filter(
                $theatrical !== [] ? $theatrical : $parsed['films'],
                fn (array $film) => empty($film['boxOfficeYen'])
            ));
            if ($missing !== []) {
                $warnings[] = $entry['name'].': 興収不明 '.count($missing).' 件';
            }
        }
        unset($entry);

        $payload = [
            'sourceUrl' => JapanSeriesWikipediaParser::SOURCE_URL,
            'sourcePage' => JapanSeriesWikipediaParser::SOURCE_PAGE,
            'sourceSection' => JapanSeriesWikipediaParser::SOURCE_SECTION,
            'retrievedAt' => now('Asia/Tokyo')->toDateString(),
            'license' => 'CC BY-SA 4.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja',
            'seriesCount' => count($series),
            'filmCount' => $filmCount,
            'series' => $series,
            'warnings' => $warnings,
            'unparsedSeries' => $unparsed,
        ];

        $this->newLine();
        $this->info(sprintf('シリーズ %d / 作品 %d / 作品表なし %d / 警告 %d', count($series), $filmCount, count($unparsed), count($warnings)));

        $conan = collect($series)->first(fn (array $item) => str_contains((string) $item['name'], '名探偵コナン'));
        if (is_array($conan)) {
            $theatrical = array_values(array_filter(
                $conan['films'] ?? [],
                fn (array $film) => ($film['kind'] ?? '') === 'theatrical'
            ));
            $this->info(sprintf('名探偵コナン 劇場版 %d 件（全 %d 件）', count($theatrical), count($conan['films'] ?? [])));
        }

        if ($this->option('dry-run')) {
            $this->warn('dry-run のためファイルは書きません');

            return self::SUCCESS;
        }

        JapanSeriesSnapshot::save($payload);
        $this->info('書き出し: '.JapanSeriesSnapshot::path());

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchParse(string $page, string $prop): array
    {
        $response = Http::wikimedia()->get('https://ja.wikipedia.org/w/api.php', [
            'action' => 'parse',
            'page' => $page,
            'prop' => $prop,
            'format' => 'json',
            'redirects' => 'true',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Wikipedia HTTP '.$response->status().' for '.$page);
        }

        $json = $response->json();
        if (! is_array($json) || isset($json['error'])) {
            throw new \RuntimeException('Wikipedia parse error for '.$page.': '.json_encode($json['error'] ?? []));
        }

        return $json;
    }
}
