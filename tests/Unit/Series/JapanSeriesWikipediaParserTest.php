<?php

namespace Tests\Unit\Series;

use App\Services\Series\JapanBoxOfficeAmount;
use App\Services\Series\JapanSeriesWikipediaParser;
use Tests\TestCase;

class JapanSeriesWikipediaParserTest extends TestCase
{
    public function test_parses_every_series_from_the_box_office_index(): void
    {
        $wikitext = file_get_contents(base_path('tests/fixtures/series/series-index.wiki'));
        $this->assertIsString($wikitext);

        $series = (new JapanSeriesWikipediaParser)->parseSeriesIndex($wikitext);
        $names = array_column($series, 'name');

        $this->assertCount(86, $series);
        $this->assertContains('名探偵コナン', $names);
        $this->assertContains('ハリー・ポッター', $names);
        $this->assertContains('ポケットモンスター', $names);
        $this->assertContains('沈黙の艦隊', $names);

        $conan = collect($series)->firstWhere('name', '名探偵コナン');
        $this->assertSame(1997, $conan['startYear']);
        $this->assertSame(149_460_000_000, $conan['reportedTotalYen']);
        $this->assertSame('名探偵コナン', $conan['slug']);
        $this->assertSame('名探偵コナン (アニメ)', $conan['wikipediaTitle']);
    }

    public function test_parses_all_conan_theatrical_films_from_the_template(): void
    {
        $html = file_get_contents(base_path('tests/fixtures/series/conan-films.html'));
        $this->assertIsString($html);

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => '名探偵コナン',
        ]);

        $theatrical = array_values(array_filter(
            $parsed['films'],
            fn (array $film) => ($film['kind'] ?? '') === 'theatrical'
        ));
        $this->assertCount(29, $theatrical);
        $this->assertSame('名探偵コナン 時計じかけの摩天楼', $theatrical[0]['title']);
        $this->assertStringContainsString('14番目の標的', $theatrical[1]['title']);
        $this->assertStringNotContainsString('mw-parser-output', (string) ($theatrical[1]['shortTitle'] ?? ''));
        $this->assertSame(1997, $theatrical[0]['releaseYear']);
        $this->assertSame(1_100_000_000, $theatrical[0]['boxOfficeYen']);
        $this->assertSame(610_000_000, $theatrical[0]['distributorRentalsYen']);
        $this->assertSame(JapanBoxOfficeAmount::METRIC_BOX_OFFICE, $theatrical[0]['metric']);

        $latest = $theatrical[28];
        $this->assertStringContainsString('ハイウェイの堕天使', $latest['title']);
        $this->assertSame(2026, $latest['releaseYear']);
        $this->assertSame(13_700_000_000, $latest['boxOfficeYen']);

        $compilations = array_values(array_filter(
            $parsed['films'],
            fn (array $film) => ($film['kind'] ?? '') === 'compilation'
        ));
        $this->assertNotSame([], $compilations);
    }

    public function test_skips_source_and_total_rows_on_worldwide_box_office_table(): void
    {
        $html = file_get_contents(base_path('tests/fixtures/series/spiderman-box-office.html'));
        $this->assertIsString($html);

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => 'スパイダーマン',
        ]);

        $titles = array_column($parsed['films'], 'title');
        $this->assertNotContains('Box Office Mojo', $titles);
        $this->assertNotContains('スパイダーマン 合計', $titles);
        $this->assertNotContains('合計', $titles);
        $this->assertContains('スパイダーマン', $titles);
        $this->assertContains('スパイダーマン2', $titles);

        foreach ($parsed['films'] as $film) {
            $this->assertFalse(str_contains((string) $film['title'], '年の映画'), $film['title']);
            $this->assertNull($film['boxOfficeYen']);
        }

        $this->assertNotSame([], $parsed['warnings']);
        $this->assertStringContainsString('世界興収表', $parsed['warnings'][0]);
    }

    public function test_inside_out_worldwide_dollar_table_is_not_read_as_yen(): void
    {
        $html = file_get_contents(base_path('tests/fixtures/series/inside-out-worldwide.html'));
        $this->assertIsString($html);

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => 'インサイド・ヘッド',
        ]);

        $titles = array_column($parsed['films'], 'title');
        $this->assertContains('インサイド・ヘッド', $titles);
        $this->assertContains('インサイド・ヘッド2', $titles);
        $this->assertNotContains('合計', $titles);
        foreach ($parsed['films'] as $film) {
            $this->assertNull($film['boxOfficeYen'], $film['title']);
        }
        $this->assertNotContains(356_460_000, array_column($parsed['films'], 'boxOfficeYen'));
        $this->assertNotContains(652_980_000, array_column($parsed['films'], 'boxOfficeYen'));
    }

    public function test_parses_ranking_table_bare_oku_numbers_and_strips_disambiguation(): void
    {
        $html = file_get_contents(base_path('tests/fixtures/series/ranking-oku-yen.html'));
        $this->assertIsString($html);

        $films = (new JapanSeriesWikipediaParser)->parseIndexRankingFilms($html);
        $byTitle = [];
        foreach ($films as $film) {
            $byTitle[$film['title']] = $film;
        }

        $this->assertArrayHasKey('スパイダーマン', $byTitle);
        $this->assertSame(7_500_000_000, $byTitle['スパイダーマン']['boxOfficeYen']);
        $this->assertSame(2002, $byTitle['スパイダーマン']['releaseYear']);
        $this->assertArrayNotHasKey('スパイダーマン (2002年の映画)', $byTitle);

        $this->assertArrayHasKey('タイタニック', $byTitle);
        $this->assertSame(27_770_000_000, $byTitle['タイタニック']['boxOfficeYen']);
        $this->assertArrayNotHasKey('タイタニック (1997年の映画)', $byTitle);
    }

    public function test_keeps_japan_yen_when_table_also_lists_dollars_and_opening_theme(): void
    {
        $html = <<<'HTML'
<table class="wikitable">
<caption>劇場版アニメ（第2期）</caption>
<tr>
<th>タイトル</th>
<th>公開日</th>
<th>オープニングテーマ</th>
<th>興行収入</th>
<th>興行収入</th>
</tr>
<tr>
<td>ドラゴンボールZ 神と神</td>
<td>2013年3月30日</td>
<td>CHA-LA HEAD-CHA-LA</td>
<td>29.9億円</td>
<td>$50,461,371</td>
</tr>
<tr>
<td>合計</td>
<td></td>
<td></td>
<td>132.4億円</td>
<td></td>
</tr>
</table>
HTML;

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => 'ドラゴンボール',
        ]);
        $titles = array_column($parsed['films'], 'title');
        $this->assertContains('ドラゴンボールZ 神と神', $titles);
        $this->assertNotContains('合計', $titles);
        $this->assertSame(2_990_000_000, $parsed['films'][0]['boxOfficeYen']);
        $this->assertSame([], $parsed['warnings']);
    }

    public function test_non_film_row_titles_are_detected(): void
    {
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('Box Office Mojo'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('The Numbers'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('合計'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('スパイダーマン 合計'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('total'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('計'));
        $this->assertFalse(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('スパイダーマン'));
        $this->assertFalse(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('名探偵コナン 時計じかけの摩天楼'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('マン・オブ・スティール オリジナル・サウンドトラック'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('2 Fast 2 Furious (soundtrack)'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('Sway With Me (GALXARA Version)'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('Furious 7 (score)'));
        $this->assertTrue(\App\Services\Series\SeriesFilmMatcher::isNonFilmRowTitle('ワイルド・スピード "How We Roll (Fast Five Remix)"'));
    }

    public function test_skips_soundtrack_and_short_tables_but_keeps_filmography(): void
    {
        $html = <<<'HTML'
<div class="mw-heading mw-heading2"><h2 id="映画">映画</h2></div>
<table class="wikitable">
<tr><th>作品名</th><th>公開日</th><th>監督</th></tr>
<tr><td>マン・オブ・スティール</td><td>2013年6月14日</td><td>ザック・スナイダー</td></tr>
<tr><td>ワンダーウーマン</td><td>2017年6月2日</td><td>パティ・ジェンキンス</td></tr>
</table>
<div class="mw-heading mw-heading2"><h2 id="サウンドトラック">サウンドトラック</h2></div>
<table class="wikitable">
<tr><th>タイトル</th><th>米国リリース日</th><th>アーティスト</th></tr>
<tr><td>マン・オブ・スティール オリジナル・サウンドトラック</td><td>2013年6月11日</td><td>ハンス・ジマー</td></tr>
<tr><td>Heathens</td><td>2016年6月16日</td><td>Twenty One Pilots</td></tr>
</table>
<div class="mw-heading mw-heading2"><h2 id="ショートフィルム">ショートフィルム</h2></div>
<table class="wikitable">
<tr><th>タイトル</th><th>米国公開日</th><th>監督</th></tr>
<tr><td>The Turbo Charged Prelude for 2 Fast 2 Furious</td><td>2003年6月3日</td><td>Philip G. Atwell</td></tr>
</table>
HTML;

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => 'DCエクステンデッド・ユニバース',
        ]);
        $titles = array_column($parsed['films'], 'title');
        $this->assertNotSame([], array_filter($titles, fn (string $title) => str_contains($title, 'マン・オブ・スティール')));
        $this->assertNotSame([], array_filter($titles, fn (string $title) => str_contains($title, 'ワンダーウーマン')));
        $this->assertNotContains('Heathens', $titles);
        $this->assertNotContains('DCエクステンデッド・ユニバース Heathens', $titles);
        $this->assertFalse((bool) array_filter($titles, fn (string $title) => str_contains($title, 'サウンドトラック')));
        $this->assertFalse((bool) array_filter($titles, fn (string $title) => str_contains($title, 'Prelude')));
    }

    public function test_fast_and_furious_film_list_keeps_later_entries(): void
    {
        $html = <<<'HTML'
<div class="mw-heading mw-heading2"><h2 id="作品一覧">作品一覧</h2></div>
<table class="wikitable">
<tr><th>タイトル</th><th>公開日</th><th>監督</th></tr>
<tr><td>ワイルド・スピード</td><td>2001年6月22日</td><td>ロブ・コーエン</td></tr>
<tr><td>ワイルド・スピード/ジェットブレイク</td><td>2021年6月25日</td><td>ジャスティン・リン</td></tr>
<tr><td>ワイルド・スピード/ファイヤーブースト</td><td>2023年5月19日</td><td>ルイ・レテリエ</td></tr>
</table>
HTML;

        $parsed = (new JapanSeriesWikipediaParser)->parseSeriesPageHtml($html, [
            'name' => 'ワイルド・スピード',
        ]);
        $titles = array_column($parsed['films'], 'title');
        $this->assertContains('ワイルド・スピード', $titles);
        $this->assertContains('ワイルド・スピード/ジェットブレイク', $titles);
        $this->assertContains('ワイルド・スピード/ファイヤーブースト', $titles);
    }
}
