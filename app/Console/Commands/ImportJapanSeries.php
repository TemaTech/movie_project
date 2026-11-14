<?php

namespace App\Console\Commands;

use App\Services\Series\JapanSeriesSnapshot;
use App\Services\Series\JapanSeriesWikipediaParser;
use App\Services\Series\SeriesFilmMatcher;
use App\Services\Series\SeriesMembership;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportJapanSeries extends Command
{
    protected $signature = 'series:import-japan
                            {--dry-run : Parse and report without writing the snapshot}
                            {--skip-tmdb : Do not query TMDB collections}';

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

        $this->info(sprintf('シリーズ %d 件を検出しました。歴代ランキング表と各シリーズページを取得します。', count($series)));

        usleep(500000);
        $indexHtmlJson = $this->fetchParse(JapanSeriesWikipediaParser::SOURCE_PAGE, 'text');
        $indexHtml = $indexHtmlJson['parse']['text']['*'] ?? '';
        $rankingFilms = $indexHtml !== '' ? $parser->parseIndexRankingFilms($indexHtml) : [];
        $this->info(sprintf('同一ページの歴代表から作品 %d 件', count($rankingFilms)));

        $warnings = [];
        $unparsed = [];
        $ambiguous = [];
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
            foreach ($parsed['warnings'] as $warning) {
                $warnings[] = $entry['name'].': '.$warning;
            }
        }
        unset($entry);

        $assigned = $this->assignRankingFilms($series, $rankingFilms);
        $ambiguous = $assigned['ambiguous'];
        $warnings = array_merge($warnings, $assigned['warnings']);

        if ($this->option('skip-tmdb')) {
            $this->copyExistingTmdbIds($series);
        }

        $hadPageFilms = [];
        foreach ($series as $entry) {
            $hadPageFilms[(string) $entry['name']] = ($entry['films'] ?? []) !== [];
        }

        $tmdbCalls = 0;
        if (! $this->option('skip-tmdb')) {
            $tmdb = TmdbClient::fromConfig();
            foreach ($series as &$entry) {
                $name = (string) ($entry['name'] ?? '');
                if (($hadPageFilms[$name] ?? false) && count($entry['films'] ?? []) >= 2) {
                    continue;
                }
                $before = $tmdb->httpCalls();
                $filled = $this->fillFromTmdb($entry, $tmdb);
                $tmdbCalls += $tmdb->httpCalls() - $before;
                $warnings = array_merge($warnings, $filled['warnings']);
                usleep(200000);
            }
            unset($entry);
        }

        foreach ($series as $entry) {
            $filmCount += count($entry['films'] ?? []);
            if (($entry['films'] ?? []) === []) {
                $unparsed[] = [
                    'name' => $entry['name'],
                    'reason' => 'no_films',
                    'wikipediaTitle' => $entry['wikipediaTitle'] ?? '',
                ];
            }
        }

        $complete = 0;
        $incomplete = 0;
        $unknownYen = 0;
        foreach ($series as $entry) {
            $films = $entry['films'] ?? [];
            $missing = 0;
            foreach ($films as $film) {
                if (empty($film['boxOfficeYen'])) {
                    $missing++;
                    $unknownYen++;
                }
            }
            if ($films !== [] && $missing === 0) {
                $complete++;
            } else {
                $incomplete++;
            }
        }

        $payload = [
            'sourceUrl' => JapanSeriesWikipediaParser::SOURCE_URL,
            'sourcePage' => JapanSeriesWikipediaParser::SOURCE_PAGE,
            'sourceSection' => JapanSeriesWikipediaParser::SOURCE_SECTION,
            'retrievedAt' => now('Asia/Tokyo')->toDateString(),
            'license' => 'CC BY-SA 4.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.ja',
            'seriesCount' => count($series),
            'filmCount' => $filmCount,
            'rankingFilmCount' => count($rankingFilms),
            'completeSeriesCount' => $complete,
            'incompleteSeriesCount' => $incomplete,
            'unknownYenCount' => $unknownYen,
            'tmdbHttpCalls' => $tmdbCalls,
            'series' => $series,
            'warnings' => $warnings,
            'unparsedSeries' => $unparsed,
            'ambiguousMatches' => $ambiguous,
        ];

        $this->newLine();
        $this->info(sprintf(
            'シリーズ %d / 作品 %d / 作品リスト完了 %d / 未完了 %d / 興収不明 %d / 曖昧 %d / TMDb HTTP %d',
            count($series),
            $filmCount,
            $complete,
            $incomplete,
            $unknownYen,
            count($ambiguous),
            $tmdbCalls
        ));

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
     * @param  list<array<string, mixed>>  $series
     * @param  list<array<string, mixed>>  $rankingFilms
     * @return array{warnings: list<string>, ambiguous: list<array<string, mixed>>}
     */
    private function assignRankingFilms(array &$series, array $rankingFilms): array
    {
        $warnings = [];
        $ambiguous = [];
        $added = 0;
        foreach ($rankingFilms as $film) {
            $assignment = SeriesMembership::assign($series, $film);
            if ($assignment['ambiguous'] !== []) {
                $ambiguous[] = [
                    'title' => $film['title'] ?? '',
                    'releaseYear' => $film['releaseYear'] ?? null,
                    'candidates' => $assignment['ambiguous'],
                ];

                continue;
            }
            if ($assignment['series'] === null) {
                continue;
            }
            $name = (string) $assignment['series']['name'];
            foreach ($series as &$entry) {
                if (($entry['name'] ?? '') !== $name) {
                    continue;
                }
                if ($this->mergeFilm($entry, $film, 'ranking_table')) {
                    $added++;
                }
                break;
            }
            unset($entry);
        }
        $warnings[] = '歴代表からシリーズへ追加 '.$added.' 件';

        return ['warnings' => $warnings, 'ambiguous' => $ambiguous];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{warnings: list<string>}
     */
    private function fillFromTmdb(array &$entry, TmdbClient $tmdb): array
    {
        $warnings = [];
        $collection = $this->findCollection($entry, $tmdb);
        if ($collection === null) {
            if (($entry['films'] ?? []) === []) {
                $warnings[] = $entry['name'].': TMDBコレクション見つからず';
            }

            return ['warnings' => $warnings];
        }

        $entry['tmdbCollectionId'] = $collection['id'] ?? null;
        $entry['tmdbCollectionName'] = $collection['name'] ?? null;
        $added = 0;
        foreach ($collection['parts'] ?? [] as $part) {
            if (! is_array($part)) {
                continue;
            }
            $title = (string) ($part['title'] ?? $part['original_title'] ?? '');
            if ($title === '') {
                continue;
            }
            $year = null;
            if (is_string($part['release_date'] ?? null) && preg_match('/^((?:19|20)\d{2})/', $part['release_date'], $matches)) {
                $year = (int) $matches[1];
            }
            $film = [
                'title' => $title,
                'shortTitle' => $part['original_title'] ?? null,
                'wikipediaTitle' => null,
                'releaseDate' => $part['release_date'] ?? null,
                'releaseYear' => $year,
                'boxOfficeYen' => null,
                'distributorRentalsYen' => null,
                'metric' => 'box_office',
                'unclear' => true,
                'kind' => 'theatrical',
                'tmdbId' => $part['id'] ?? null,
                'source' => 'tmdb_collection',
            ];
            if ($this->mergeFilm($entry, $film, 'tmdb_collection')) {
                $added++;
            }
        }
        if ($added > 0) {
            $warnings[] = $entry['name'].': TMDBコレクションから '.$added.' 件追加';
        }

        return ['warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function findCollection(array $entry, TmdbClient $tmdb): ?array
    {
        foreach (SeriesMembership::aliases($entry) as $alias) {
            $results = $tmdb->searchCollections($alias, 'ja');
            if ($results === []) {
                $results = $tmdb->searchCollections($alias, 'en-US');
            }
            foreach ($results as $result) {
                $id = (int) ($result['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $collection = $tmdb->getCollection($id, 'ja') ?? $tmdb->getCollection($id, 'en-US');
                if (is_array($collection) && is_array($collection['parts'] ?? null) && $collection['parts'] !== []) {
                    return $collection;
                }
            }
        }

        $first = $entry['films'][0] ?? null;
        if (is_array($first) && ($first['title'] ?? '') !== '') {
            $movies = $tmdb->searchMovies((string) $first['title'], $first['releaseYear'] ?? null, 'ja');
            foreach ($movies as $movie) {
                $id = (int) ($movie['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $detail = $tmdb->getMovie($id, 'ja');
                $belongs = is_array($detail['belongs_to_collection'] ?? null) ? $detail['belongs_to_collection'] : null;
                $collectionId = (int) ($belongs['id'] ?? 0);
                if ($collectionId > 0) {
                    return $tmdb->getCollection($collectionId, 'ja') ?? $tmdb->getCollection($collectionId, 'en-US');
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $film
     */
    private function mergeFilm(array &$entry, array $film, string $source): bool
    {
        $entry['films'] ??= [];
        $incomingTmdb = SeriesFilmMatcher::tmdbId($film);
        $incoming = SeriesFilmMatcher::identityCompact((string) ($film['title'] ?? ''));
        $incomingYear = SeriesFilmMatcher::year($film);
        foreach ($entry['films'] as &$existing) {
            if (! is_array($existing)) {
                continue;
            }
            $existingTmdb = SeriesFilmMatcher::tmdbId($existing);
            $have = SeriesFilmMatcher::identityCompact((string) ($existing['title'] ?? ''));
            $tmdbHit = $incomingTmdb !== null && $existingTmdb !== null && $incomingTmdb === $existingTmdb;
            $titleHit = $have !== '' && $incoming !== '' && $have === $incoming;
            if (! $tmdbHit && ! $titleHit) {
                continue;
            }
            $existingYear = SeriesFilmMatcher::year($existing);
            if (! $tmdbHit && $incomingYear && $existingYear && abs($incomingYear - $existingYear) > 1) {
                continue;
            }
            if (empty($existing['boxOfficeYen']) && ! empty($film['boxOfficeYen'])) {
                $existing['boxOfficeYen'] = $film['boxOfficeYen'];
                $existing['metric'] = $film['metric'] ?? $existing['metric'];
                $existing['unclear'] = false;
            }
            if (empty($existing['releaseYear']) && $incomingYear) {
                $existing['releaseYear'] = $incomingYear;
                $existing['releaseDate'] = $film['releaseDate'] ?? $existing['releaseDate'] ?? null;
            }
            if (empty($existing['tmdbId']) && ! empty($film['tmdbId'])) {
                $existing['tmdbId'] = $film['tmdbId'];
            }

            return false;
        }
        unset($existing);

        $film['source'] = $film['source'] ?? $source;
        $entry['films'][] = $film;
        usort($entry['films'], function (array $a, array $b): int {
            $yearA = $a['releaseYear'] ?? 9999;
            $yearB = $b['releaseYear'] ?? 9999;
            if ($yearA !== $yearB) {
                return $yearA <=> $yearB;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return true;
    }

    /**
     * --skip-tmdb 時も既存スナップショットの TMDB id を落とさない。
     *
     * @param  list<array<string, mixed>>  $series
     */
    private function copyExistingTmdbIds(array &$series): void
    {
        $existingByName = [];
        foreach (JapanSeriesSnapshot::series() as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $existingByName[$name] = is_array($row['films'] ?? null) ? $row['films'] : [];
            }
        }

        foreach ($series as &$entry) {
            $oldFilms = $existingByName[(string) ($entry['name'] ?? '')] ?? [];
            if ($oldFilms === []) {
                continue;
            }
            foreach ($entry['films'] ?? [] as &$film) {
                if (! is_array($film) || ! empty($film['tmdbId'])) {
                    continue;
                }
                $incoming = SeriesFilmMatcher::identityCompact((string) ($film['title'] ?? ''));
                $incomingYear = SeriesFilmMatcher::year($film);
                foreach ($oldFilms as $old) {
                    if (! is_array($old) || empty($old['tmdbId'])) {
                        continue;
                    }
                    $have = SeriesFilmMatcher::identityCompact((string) ($old['title'] ?? ''));
                    if ($incoming === '' || $have === '' || $incoming !== $have) {
                        continue;
                    }
                    $oldYear = SeriesFilmMatcher::year($old);
                    if ($incomingYear && $oldYear && abs($incomingYear - $oldYear) > 1) {
                        continue;
                    }
                    $film['tmdbId'] = $old['tmdbId'];
                    break;
                }
            }
            unset($film);
        }
        unset($entry);
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
