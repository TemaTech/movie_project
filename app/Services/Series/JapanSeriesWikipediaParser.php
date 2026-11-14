<?php

namespace App\Services\Series;

use DOMDocument;
use DOMElement;
use DOMXPath;

class JapanSeriesWikipediaParser
{
    public const SOURCE_PAGE = '日本歴代興行成績上位の映画一覧';

    public const SOURCE_SECTION = '映画シリーズの興行成績';

    public const SOURCE_URL = 'https://ja.wikipedia.org/wiki/'.'%E6%97%A5%E6%9C%AC%E6%AD%B4%E4%BB%A3%E8%88%88%E8%A1%8C%E6%88%90%E7%B8%BE%E4%B8%8A%E4%BD%8D%E3%81%AE%E6%98%A0%E7%94%BB%E4%B8%80%E8%A6%A7';

    /**
     * @return list<array<string, mixed>>
     */
    public function parseSeriesIndex(string $wikitext): array
    {
        $section = $this->extractSection($wikitext, '映画シリーズの興行成績');
        if ($section === null) {
            return [];
        }

        $boxOffice = $this->extractSection($section, '興行収入', 3) ?? $section;
        $table = $this->firstWikiTable($boxOffice);
        if ($table === null) {
            return [];
        }

        $series = [];
        $usedSlugs = [];
        foreach (preg_split('/\n\|-/', $table) ?: [] as $row) {
            $row = preg_replace('/\|\}.*$/s', '', $row) ?? $row;
            if (str_contains($row, '{|') || str_contains($row, '!シリーズ名')) {
                continue;
            }

            $parsed = $this->parseIndexRow($row);
            if ($parsed === null) {
                continue;
            }

            $parsed['slug'] = SeriesSlug::unique($parsed['name'], $usedSlugs);
            $usedSlugs[] = $parsed['slug'];
            $series[] = $parsed;
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $series
     * @return array{films: list<array<string, mixed>>, warnings: list<string>}
     */
    public function parseSeriesPageHtml(string $html, array $series): array
    {
        $warnings = [];
        $films = [];
        $section = is_string($series['wikipediaSection'] ?? null) ? $series['wikipediaSection'] : null;
        $html = $this->clipToSection($html, $section);

        foreach ($this->filmTables($html) as $tableInfo) {
            if ($tableInfo['kind'] === 'world') {
                $warnings[] = '世界興収表を日本興収としては使わず、作品名のみ採用: '.$tableInfo['caption'];
            }

            foreach ($tableInfo['rows'] as $row) {
                $film = $this->filmFromRow($row, $series, $tableInfo);
                if ($film === null) {
                    continue;
                }

                $compact = SeriesFilmMatcher::identityCompact((string) $film['title']);
                if ($compact === '') {
                    continue;
                }

                $merged = false;
                foreach ($films as $index => $existing) {
                    if (! SeriesFilmMatcher::isSameFilm($existing, $film)) {
                        continue;
                    }
                    $films[$index] = $this->mergeParsedFilm($existing, $film);
                    $merged = true;
                    break;
                }
                if ($merged) {
                    continue;
                }

                $films[] = $film;
            }
        }

        usort($films, function (array $a, array $b): int {
            $yearA = $a['releaseYear'] ?? 9999;
            $yearB = $b['releaseYear'] ?? 9999;
            if ($yearA !== $yearB) {
                return $yearA <=> $yearB;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return ['films' => $films, 'warnings' => $warnings];
    }

    /**
     * 同一ページの歴代ランキング表（興収・配収）から作品を拾う。シリーズ合計表は除外する。
     *
     * @return list<array<string, mixed>>
     */
    public function parseIndexRankingFilms(string $html): array
    {
        $films = [];
        $seen = [];
        foreach ($this->filmTables($html) as $tableInfo) {
            if ($tableInfo['kind'] === 'world') {
                continue;
            }
            foreach ($tableInfo['rows'] as $row) {
                $film = $this->filmFromRow($row, ['name' => ''], $tableInfo);
                if ($film === null || ($film['title'] ?? '') === '') {
                    continue;
                }
                $key = SeriesFilmMatcher::identityCompact($film['title']).'|'.($film['releaseYear'] ?? '');
                if (isset($seen[$key])) {
                    if (($films[$seen[$key]]['boxOfficeYen'] ?? null) === null && ($film['boxOfficeYen'] ?? null) !== null) {
                        $films[$seen[$key]] = $film;
                    }

                    continue;
                }
                $seen[$key] = count($films);
                $films[] = $film;
            }
        }

        return array_values($films);
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $tableInfo
     * @param  array<int, array{text: string, html: string, header: bool}>  $row
     * @return array<string, mixed>|null
     */
    private function filmFromRow(array $row, array $series, array $tableInfo): ?array
    {
        $roles = $tableInfo['roles'];
        $titleCell = $this->cellByRole($row, $roles, 'title') ?? $this->firstTitleCell($row);
        if ($titleCell === null) {
            return null;
        }

        [$title, $shortTitle, $wikiTitle] = $this->extractTitles($titleCell['html'], $titleCell['text'], (string) ($series['name'] ?? ''));
        if ($title === '') {
            return null;
        }

        foreach ([$title, (string) $shortTitle, (string) $wikiTitle, $titleCell['text']] as $candidate) {
            if ($this->looksLikeNonFilmTitle($candidate)) {
                return null;
            }
        }

        $yearCell = $this->cellByRole($row, $roles, 'date') ?? $this->cellByRole($row, $roles, 'year');
        [$releaseDate, $releaseYear] = $this->extractRelease($yearCell['text'] ?? $titleCell['text']);

        $boxOfficeYen = null;
        $rentalsYen = null;
        $metric = JapanBoxOfficeAmount::METRIC_BOX_OFFICE;
        $unclear = false;

        if ($tableInfo['kind'] !== 'world') {
            $boCell = $this->cellByRole($row, $roles, 'box_office');
            $rentCell = $this->cellByRole($row, $roles, 'rentals');
            if ($boCell !== null) {
                $parsed = JapanBoxOfficeAmount::parse(
                    $boCell['text'],
                    JapanBoxOfficeAmount::METRIC_BOX_OFFICE,
                    $this->unitForRole($roles, 'box_office'),
                );
                if ($parsed !== null) {
                    $boxOfficeYen = $parsed['yen'];
                } elseif (! JapanBoxOfficeAmount::isMissing($boCell['text'])) {
                    $unclear = true;
                }
            }
            if ($rentCell !== null) {
                $parsed = JapanBoxOfficeAmount::parse(
                    $rentCell['text'],
                    JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS,
                    $this->unitForRole($roles, 'rentals'),
                );
                if ($parsed !== null) {
                    $rentalsYen = $parsed['yen'];
                }
            }

            if ($boxOfficeYen === null && $rentalsYen !== null) {
                $boxOfficeYen = $rentalsYen;
                $metric = JapanBoxOfficeAmount::METRIC_DISTRIBUTOR_RENTALS;
            } elseif ($boxOfficeYen === null && $boCell === null && $rentCell === null) {
                foreach ($row as $cell) {
                    if ($cell['header']) {
                        continue;
                    }
                    $parsed = JapanBoxOfficeAmount::parse($cell['text']);
                    if ($parsed !== null) {
                        $boxOfficeYen = $parsed['yen'];
                        $metric = $parsed['metric'];
                        break;
                    }
                }
            }
        } else {
            $unclear = true;
            $boxOfficeYen = null;
        }

        $kind = $tableInfo['filmKind'];

        return [
            'title' => $title,
            'shortTitle' => $shortTitle,
            'wikipediaTitle' => $wikiTitle,
            'releaseDate' => $releaseDate,
            'releaseYear' => $releaseYear,
            'boxOfficeYen' => $boxOfficeYen,
            'distributorRentalsYen' => $rentalsYen,
            'metric' => $metric,
            'unclear' => $unclear && $boxOfficeYen === null,
            'kind' => $kind,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeParsedFilm(array $existing, array $incoming): array
    {
        foreach (['boxOfficeYen', 'distributorRentalsYen', 'releaseDate', 'wikipediaTitle', 'shortTitle'] as $field) {
            if (($existing[$field] ?? null) === null && ($incoming[$field] ?? null) !== null) {
                $existing[$field] = $incoming[$field];
            }
        }
        $incomingYear = is_numeric($incoming['releaseYear'] ?? null) ? (int) $incoming['releaseYear'] : null;
        $existingYear = is_numeric($existing['releaseYear'] ?? null) ? (int) $existing['releaseYear'] : null;
        $wikiYear = SeriesFilmMatcher::wikipediaDisambiguationYear((string) ($existing['wikipediaTitle'] ?? $incoming['wikipediaTitle'] ?? ''));
        if ($incomingYear !== null && ($existingYear === null || ($wikiYear === null && $incomingYear > $existingYear))) {
            $existing['releaseYear'] = $incomingYear;
            if (($incoming['releaseDate'] ?? null) !== null) {
                $existing['releaseDate'] = $incoming['releaseDate'];
            }
        }
        if (! empty($incoming['boxOfficeYen']) && empty($existing['boxOfficeYen'])) {
            $existing['metric'] = $incoming['metric'] ?? $existing['metric'];
            $existing['unclear'] = false;
        } elseif (! empty($existing['boxOfficeYen'])) {
            $existing['unclear'] = false;
        }

        return $existing;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filmTables(string $html): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " wikitable ")]');
        if ($tables === false) {
            return [];
        }

        $out = [];
        foreach ($tables as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $grid = $this->tableGrid($table);
            if ($grid === []) {
                continue;
            }

            [$headerRow, $headerIndex] = $this->bestHeaderRow($grid);
            if ($headerRow === null) {
                continue;
            }

            $class = ' '.$table->getAttribute('class').' ';
            if (str_contains($class, ' navbox ') || str_contains($class, ' infobox ')) {
                continue;
            }

            $headerTexts = array_map(fn (array $cell) => $cell['text'], $headerRow);
            $joined = implode(' ', $headerTexts);
            $caption = $this->captionText($table);
            $heading = $this->precedingHeadingText($xpath, $table);
            if (str_contains($joined, 'シリーズ名') || preg_match('/シリーズの興行/u', $caption)) {
                continue;
            }
            if ($this->isNonFilmSection($heading, $caption)
                || $this->isTvTable($joined)
                || $this->isAttendanceOnlyTable($joined, $headerTexts)
                || $this->isPartialGrossTable($joined, $caption)) {
                continue;
            }

            $roles = $this->headerRoles($headerTexts);
            $roles['_headerIndex'] = $headerIndex;
            $roles['_headers'] = $headerTexts;
            $kind = $this->isWorldTable($this->tableWorldContext($grid, $joined, $caption, $headerIndex)) ? 'world' : 'japan';
            if ($roles['title'] === null) {
                continue;
            }
            $isFilmList = $this->isFilmListTable($joined, $roles, $heading, $caption);
            if ($kind === 'world' && ! str_contains($joined, '興行収入') && ! str_contains($caption, '興行')) {
                continue;
            }
            if ($kind === 'japan' && $roles['box_office'] === null && $roles['rentals'] === null && ! $isFilmList) {
                continue;
            }

            $filmKind = $this->filmKindFromCaption($caption.$joined);

            $rows = [];
            foreach ($grid as $index => $row) {
                if ($index <= (int) ($roles['_headerIndex'] ?? 0)) {
                    continue;
                }
                if ($this->rowIsHeader($row)) {
                    continue;
                }
                $rows[] = $row;
            }

            if ($rows === []) {
                continue;
            }

            $out[] = [
                'kind' => $kind,
                'roles' => $roles,
                'rows' => $rows,
                'caption' => $caption,
                'filmKind' => $filmKind,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{text: string, html: string, header: bool}>  $row
     */
    private function cellByRole(array $row, array $roles, string $role): ?array
    {
        $index = $roles[$role] ?? null;
        if (! is_int($index) || ! isset($row[$index])) {
            return null;
        }

        return $row[$index];
    }

    /**
     * @param  list<array{text: string, html: string, header: bool}>  $row
     * @return array{text: string, html: string, header: bool}|null
     */
    private function firstTitleCell(array $row): ?array
    {
        foreach ($row as $cell) {
            if ($cell['header']) {
                continue;
            }
            if ($this->extractLinkTitle($cell['html']) !== null || mb_strlen($cell['text']) >= 2) {
                return $cell;
            }
        }

        return $row[1] ?? $row[0] ?? null;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function extractTitles(string $html, string $text, string $seriesName): array
    {
        $link = $this->extractLinkTitle($html);
        $visible = JapanBoxOfficeAmount::normalize($text);
        $visible = preg_replace('/^第?\d+\s*作/u', '', $visible) ?? $visible;
        $visible = trim($visible);

        $wikiTitle = $link['title'] ?? null;
        $short = $link['text'] ?? ($visible !== '' ? $visible : null);

        $title = $wikiTitle ?: $short ?: $visible;
        $title = str_replace('_', ' ', (string) $title);
        $title = SeriesFilmMatcher::displayTitle($title);
        $short = $short !== null ? SeriesFilmMatcher::displayTitle($short) : null;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
        $title = trim($title);

        if ($seriesName !== '' && $title !== '' && ! str_contains($title, $seriesName) && mb_strlen($title) < 40) {
            $wikiDisplay = $wikiTitle ? SeriesFilmMatcher::displayTitle(str_replace('_', ' ', $wikiTitle)) : '';
            if ($wikiDisplay !== '') {
                $title = $wikiDisplay;
            } else {
                $title = $seriesName.' '.$title;
            }
        }

        return [$title, $short, $wikiTitle];
    }

    /**
     * @return array{title: string, text: string}|null
     */
    private function extractLinkTitle(string $html): ?array
    {
        if (! preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/su', $html, $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $inner = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/isu', '', $match[2]) ?? $match[2];
            $label = JapanBoxOfficeAmount::normalize(strip_tags($inner));
            if ($label === '' || preg_match('/^\d{4}年?$/', $label) || str_contains($href, 'redlink=1')) {
                continue;
            }
            if (preg_match('~/wiki/([^?#]+)(?:#([^"]*))?~', $href, $wiki)) {
                $title = urldecode(str_replace('_', ' ', $wiki[1]));
                $fragment = isset($wiki[2]) ? urldecode(str_replace('_', ' ', $wiki[2])) : '';
                if ($fragment !== '' && $label !== '' && $label !== $title) {
                    $title = $label;
                }
                if ($this->looksLikeNonFilmTitle($title) && ! $this->looksLikeFilmPage($title)) {
                    continue;
                }

                return ['title' => $title, 'text' => $label];
            }
        }

        return null;
    }

    private function looksLikeFilmPage(string $title): bool
    {
        return str_contains($title, '映画')
            || str_contains($title, '劇場')
            || preg_match('/\d{4}/', $title) === 1
            || str_contains($title, 'THE MOVIE')
            || str_contains($title, 'vs');
    }

    private function looksLikeNonFilmTitle(string $title): bool
    {
        return SeriesFilmMatcher::isNonFilmRowTitle($title);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function extractRelease(string $text): array
    {
        $text = JapanBoxOfficeAmount::normalize($text);
        $year = null;
        $date = null;

        if (preg_match('/((?:19|20)\d{2})年\s*(\d{1,2})月\s*(\d{1,2})日/u', $text, $matches)) {
            $year = (int) $matches[1];
            $date = sprintf('%04d-%02d-%02d', $year, (int) $matches[2], (int) $matches[3]);
        } elseif (preg_match('/((?:19|20)\d{2})-(\d{2})-(\d{2})/', $text, $matches)) {
            $year = (int) $matches[1];
            $date = $matches[0];
        } elseif (preg_match('/((?:19|20)\d{2})/', $text, $matches)) {
            $year = (int) $matches[1];
        }

        return [$date, $year];
    }

    /**
     * @param  list<list<array{text: string, html: string, header: bool}>>  $grid
     * @return array{0: list<array{text: string, html: string, header: bool}>|null, 1: int}
     */
    private function bestHeaderRow(array $grid): array
    {
        $best = null;
        $bestIndex = 0;
        $bestScore = -1;
        foreach ($grid as $index => $row) {
            $texts = array_map(fn (array $cell) => $cell['text'], $row);
            $score = $this->headerScore($texts);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
                $bestIndex = $index;
            }
        }

        return [$bestScore > 0 ? $best : null, $bestIndex];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    private function headerRoles(array $headers): array
    {
        $roles = [
            'title' => null,
            'date' => null,
            'year' => null,
            'box_office' => null,
            'rentals' => null,
            '_headerIndex' => 0,
        ];

        foreach ($headers as $index => $header) {
            $h = JapanBoxOfficeAmount::normalize($header);
            if ($roles['title'] === null && preg_match('/題名|タイトル|作品名|^作品$|映画名/u', $h)) {
                $roles['title'] = $index;
            } elseif ($roles['date'] === null && preg_match('/公開日|公開/u', $h)) {
                $roles['date'] = $index;
            } elseif ($roles['year'] === null && preg_match('/年/u', $h) && ! str_contains($h, '興行')) {
                $roles['year'] = $index;
            } elseif ($roles['box_office'] === null && str_contains($h, '興行収入') && ! str_contains($h, '配給')) {
                $roles['box_office'] = $index;
            } elseif ($roles['rentals'] === null && preg_match('/配給収入|配収/u', $h)) {
                $roles['rentals'] = $index;
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>  $headers
     */
    private function headerScore(array $headers): int
    {
        $joined = implode(' ', $headers);
        $score = 0;
        if (preg_match('/題名|タイトル/u', $joined)) {
            $score += 3;
        } elseif (preg_match('/(?:^|\s)作品(?:名)?(?:\s|$)/u', $joined)) {
            $score += 2;
        }
        if (preg_match('/首位|記録|内容別/u', $joined) && ! preg_match('/題名|タイトル/u', $joined)) {
            $score -= 8;
        }
        if (str_contains($joined, '興行収入')) {
            $score += 4;
        }
        if (preg_match('/配給収入|配収/u', $joined)) {
            $score += 3;
        }
        if (str_contains($joined, '公開')) {
            $score += 2;
        }
        if ($this->isWorldTable($joined)) {
            $score -= 2;
        }
        if ($this->isTvTable($joined)) {
            $score -= 10;
        }

        return $score;
    }

    private function isWorldTable(string $joined): bool
    {
        if (preg_match('/億円|万円|\d(?:\.\d+)?億(?!ドル)/u', $joined)) {
            return false;
        }

        return (bool) preg_match('/北米|全世界|北アメリカ|インフレ|他地域|ドル|USD|Box Office Mojo|The Numbers/u', $joined)
            || (bool) preg_match('/\$\d/', $joined);
    }

    private function isNonFilmSection(string $heading, string $caption): bool
    {
        $text = $heading.' '.$caption;
        if (preg_match('/作品一覧|劇場版|映画作品|(?:^|[\s・])映画(?:$|[\s・])|興行/u', $text)
            && ! preg_match('/サウンドトラック|soundtrack|シングル|ショートフィルム|短編|テレビシリーズ/iu', $text)) {
            return false;
        }

        return (bool) preg_match('/サウンドトラック|soundtrack|シングル|楽曲|主題歌|挿入歌|スコア|\bScores?\b|アルバム|\bAlbums?\b|ミュージック|music video|ショートフィルム|短編|小説|ノベライズ|コミカライズ|ゲーム作品|テレビシリーズ|テレビドラマ|吹き替え|登場キャラクター|批評家|レビュー|公開されることのなかった|未製作|関連項目|外部リンク/iu', $text);
    }

    /**
     * @param  array<string, mixed>  $roles
     */
    private function isFilmListTable(string $joined, array $roles, string $heading, string $caption): bool
    {
        if ($roles['title'] === null) {
            return false;
        }
        if (preg_match('/アーティスト|レーベル|\bLabel\b|曲名/u', $joined)) {
            return false;
        }
        $section = $heading.' '.$caption;
        if ($section !== '' && ! preg_match('/作品一覧|劇場版|映画|興行/u', $section)) {
            return false;
        }
        if (preg_match('/テレビシリーズ|エピソード|シーズン/u', $section)) {
            return false;
        }

        return ($roles['date'] ?? null) !== null
            || ($roles['year'] ?? null) !== null
            || (bool) preg_match('/監督/u', $joined);
    }

    private function precedingHeadingText(DOMXPath $xpath, DOMElement $table): string
    {
        $nodes = $xpath->query('(preceding::h2 | preceding::h3 | preceding::h4)[last()]', $table);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);
        if (! $node instanceof DOMElement) {
            return '';
        }

        return JapanBoxOfficeAmount::normalize($node->textContent ?? '');
    }

    private function isPartialGrossTable(string $joined, string $caption): bool
    {
        $text = $caption.' '.$joined;

        return (bool) preg_match('/オープニング(?:週末|興行|成績)|初日興行|先行上映|初週末/u', $text);
    }

    /**
     * @param  list<list<array{text: string, html: string, header: bool}>>  $grid
     */
    private function tableWorldContext(array $grid, string $joined, string $caption, int $headerIndex): string
    {
        $chunks = [$joined, $caption];
        foreach ($grid as $index => $row) {
            if ($index > $headerIndex + 2) {
                break;
            }
            foreach ($row as $cell) {
                $chunks[] = $cell['text'];
            }
        }

        return implode(' ', $chunks);
    }

    /**
     * @param  array<string, mixed>  $roles
     */
    private function unitForRole(array $roles, string $role): ?string
    {
        $index = $roles[$role] ?? null;
        $headers = $roles['_headers'] ?? [];
        if (! is_int($index) || ! isset($headers[$index])) {
            return null;
        }

        return JapanBoxOfficeAmount::unitFromHeader((string) $headers[$index]);
    }

    private function isTvTable(string $joined): bool
    {
        return (bool) preg_match('/話数|放送日|視聴率|エピソード/u', $joined);
    }

    /**
     * @param  list<string>  $headers
     */
    private function isAttendanceOnlyTable(string $joined, array $headers): bool
    {
        return str_contains($joined, '観客動員') && ! str_contains($joined, '興行収入') && ! str_contains($joined, '配給収入');
    }

    private function filmKindFromCaption(string $caption): string
    {
        if (preg_match('/総集編|特別編集/u', $caption)) {
            return 'compilation';
        }
        if (preg_match('/コラボ|クロスオーバー/u', $caption)) {
            return 'crossover';
        }

        return 'theatrical';
    }

    /**
     * @param  list<array{text: string, html: string, header: bool}>  $row
     */
    private function rowIsHeader(array $row): bool
    {
        $headers = 0;
        foreach ($row as $cell) {
            if ($cell['header']) {
                $headers++;
            }
        }

        return $headers > 0 && $headers >= (int) ceil(count($row) * 0.6);
    }

    /**
     * @return list<list<array{text: string, html: string, header: bool}>>
     */
    private function tableGrid(DOMElement $table): array
    {
        $grid = [];
        $pending = [];
        $rowIndex = 0;

        foreach ($this->directRows($table) as $tr) {

            $col = 0;
            $grid[$rowIndex] = [];

            foreach ($tr->childNodes as $cell) {
                if (! $cell instanceof DOMElement || ! in_array($cell->tagName, ['td', 'th'], true)) {
                    continue;
                }

                while (isset($pending[$col]) && $pending[$col]['left'] > 0) {
                    $grid[$rowIndex][$col] = $pending[$col]['cell'];
                    $pending[$col]['left']--;
                    if ($pending[$col]['left'] === 0) {
                        unset($pending[$col]);
                    }
                    $col++;
                }

                $colspan = max(1, (int) $cell->getAttribute('colspan'));
                $rowspan = max(1, (int) $cell->getAttribute('rowspan'));
                $payload = [
                    'text' => JapanBoxOfficeAmount::normalize($this->visibleText($cell)),
                    'html' => $this->innerHtml($cell),
                    'header' => $cell->tagName === 'th',
                ];

                for ($i = 0; $i < $colspan; $i++) {
                    $grid[$rowIndex][$col] = $payload;
                    if ($rowspan > 1) {
                        $pending[$col] = ['left' => $rowspan - 1, 'cell' => $payload];
                    }
                    $col++;
                }
            }

            while (isset($pending[$col]) && $pending[$col]['left'] > 0) {
                $grid[$rowIndex][$col] = $pending[$col]['cell'];
                $pending[$col]['left']--;
                if ($pending[$col]['left'] === 0) {
                    unset($pending[$col]);
                }
                $col++;
            }

            ksort($grid[$rowIndex]);
            $rowIndex++;
        }

        return array_values(array_filter($grid, fn (array $row) => $row !== []));
    }

    /**
     * @return list<DOMElement>
     */
    private function directRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->tagName === 'tr') {
                $rows[] = $child;

                continue;
            }
            if (in_array($child->tagName, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $row) {
                    if ($row instanceof DOMElement && $row->tagName === 'tr') {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    private function visibleText(DOMElement $element): string
    {
        $clone = $element->cloneNode(true);
        if (! $clone instanceof DOMElement) {
            return $element->textContent ?? '';
        }
        $xpath = new DOMXPath($clone->ownerDocument);
        $nodes = $xpath->query('.//style|.//script', $clone);
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $clone->textContent ?? '';
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    private function captionText(DOMElement $table): string
    {
        foreach ($table->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'caption') {
                return JapanBoxOfficeAmount::normalize($child->textContent ?? '');
            }
        }

        return '';
    }

    private function clipToSection(string $html, ?string $section): string
    {
        if ($section === null || $section === '') {
            return $html;
        }

        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $headings = $xpath->query('//h1|//h2|//h3|//h4');
        if ($headings === false) {
            return $html;
        }

        $start = null;
        foreach ($headings as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }
            $id = html_entity_decode($heading->getAttribute('id'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = JapanBoxOfficeAmount::normalize($heading->textContent ?? '');
            if ($id === $section || $text === $section || str_ends_with($id, $section)) {
                $start = $heading;
                break;
            }
        }

        if ($start === null) {
            return $html;
        }

        $level = (int) substr($start->tagName, 1);
        $block = $start;
        $parent = $start->parentNode;
        if ($parent instanceof DOMElement && str_contains($parent->getAttribute('class'), 'mw-heading')) {
            $block = $parent;
        }

        $htmlOut = $dom->saveHTML($block) ?: '';
        $node = $block->nextSibling;
        while ($node !== null) {
            if ($node instanceof DOMElement) {
                $heading = $this->headingElement($node);
                if ($heading !== null) {
                    $nextLevel = (int) substr($heading->tagName, 1);
                    if ($nextLevel <= $level) {
                        break;
                    }
                }
            }
            $htmlOut .= $dom->saveHTML($node) ?: '';
            $node = $node->nextSibling;
        }

        return $htmlOut;
    }

    private function headingElement(DOMElement $node): ?DOMElement
    {
        if (preg_match('/^h[1-4]$/', $node->tagName)) {
            return $node;
        }
        if (str_contains($node->getAttribute('class'), 'mw-heading')) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && preg_match('/^h[1-4]$/', $child->tagName)) {
                    return $child;
                }
            }
        }

        return null;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8">'.$html;
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseIndexRow(string $row): ?array
    {
        if (! preg_match('/\[\[([^|\]]+)(?:\|([^\]]+))?\]\]/u', $row, $link)) {
            return null;
        }

        $wikipediaTarget = $link[1];
        $name = trim($link[2] ?? $link[1]);
        $name = preg_replace('/^劇場版\s*/u', '', $name) ?? $name;

        [$page, $section] = array_pad(explode('#', $wikipediaTarget, 2), 2, null);

        $cells = [];
        foreach (preg_split('/\n/', $row) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|')) {
                $cells[] = trim(substr($line, 1));
            }
        }

        $totalYen = null;
        $startYear = null;
        $notes = null;
        foreach ($cells as $index => $cell) {
            if ($index === 0) {
                continue;
            }
            $plain = JapanBoxOfficeAmount::normalize($cell);
            if ($totalYen === null) {
                $parsed = JapanBoxOfficeAmount::parse($plain, JapanBoxOfficeAmount::METRIC_BOX_OFFICE);
                if ($parsed !== null) {
                    $totalYen = $parsed['yen'];

                    continue;
                }
                if (preg_match('/^(\d+(?:\.\d+)?)$/', $plain, $numeric)) {
                    $totalYen = (int) round((float) $numeric[1] * 100000000);

                    continue;
                }
            }
            if ($startYear === null && preg_match('/^((?:19|20)\d{2})$/', $plain, $yearMatch)) {
                $startYear = (int) $yearMatch[1];

                continue;
            }
            if ($notes === null && $plain !== '' && ! preg_match('/^\[\[/', $cell)) {
                $notes = $plain !== '' ? $plain : null;
            }
        }

        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'wikipediaTitle' => $page,
            'wikipediaSection' => $section,
            'wikipediaUrl' => 'https://ja.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', (string) $page)).($section ? '#'.rawurlencode($section) : ''),
            'startYear' => $startYear,
            'reportedTotalYen' => $totalYen,
            'notes' => $notes,
            'films' => [],
        ];
    }

    private function extractSection(string $wikitext, string $heading, int $level = 2): ?string
    {
        $marks = str_repeat('=', $level);
        $pattern = '/^'.$marks.'\s*'.preg_quote($heading, '/').'\s*'.$marks.'\s*$/um';
        if (! preg_match($pattern, $wikitext, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $rest = substr($wikitext, $start);
        if (preg_match('/^={1,'.$level.'}[^=].*$/um', $rest, $next, PREG_OFFSET_CAPTURE)) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    private function firstWikiTable(string $wikitext): ?string
    {
        if (! preg_match('/\{\|[\s\S]*?\|\}/', $wikitext, $match)) {
            return null;
        }

        return $match[0];
    }
}
