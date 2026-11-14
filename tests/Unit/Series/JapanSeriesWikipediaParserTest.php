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
}
