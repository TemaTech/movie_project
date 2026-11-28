<?php

namespace Tests\Unit\Series;

use App\Services\Series\PopularSeriesLinks;
use App\Services\Series\SeriesCatalog;
use Tests\TestCase;

class PopularSeriesLinksTest extends TestCase
{
    public function test_world_picks_top_series_then_top_brands_by_total(): void
    {
        $series = ['region' => 'global', 'series' => [
            $this->series('avengers', 'アベンジャーズ シリーズ', '/series/global/avengers/', 7_900),
            $this->series('star-wars', 'スター・ウォーズ シリーズ', '/series/global/star-wars/', 8_900),
            $this->series('twilight', 'Twilight Collection', '/series/global/twilight/', 8_000),
            $this->series('hp', 'ハリー・ポッター コレクション', '/series/global/hp/', 7_700),
            $this->series('thin', '薄いシリーズ', '/series/global/thin/', 9_999, ['indexable' => false]),
            $this->series('empty', '空のシリーズ', '/series/global/empty/', 9_998, ['filmCount' => 0, 'films' => []]),
            $this->series('', '無名シリーズ', '/series/global/x/', 9_997),
            $this->series('unknown', '不明シリーズ', '/series/global/unknown/', null),
        ]];
        $brands = ['region' => 'global', 'kind' => 'brand', 'series' => [
            $this->series('pixar', 'ピクサー', '/series/global/brand/pixar/', 13_000, ['titleName' => 'ピクサー映画']),
            $this->series('mcu', 'MCU', '/series/global/brand/mcu/', 29_000, ['titleName' => 'MCU映画']),
            $this->series('dc', 'DC', '/series/global/brand/dc/', 8_800, ['titleName' => 'DC映画']),
        ]];

        $links = PopularSeriesLinks::pick($series, $brands, 2, 2);

        $this->assertSame([
            ['name' => 'スター・ウォーズ シリーズ', 'href' => '/series/global/star-wars/', 'kind' => 'series'],
            ['name' => 'アベンジャーズ シリーズ', 'href' => '/series/global/avengers/', 'kind' => 'series'],
            ['name' => 'MCU映画', 'href' => '/series/global/brand/mcu/', 'kind' => 'brand'],
            ['name' => 'ピクサー映画', 'href' => '/series/global/brand/pixar/', 'kind' => 'brand'],
        ], $links);

        $all = array_column(PopularSeriesLinks::pick($series, $brands), 'href');
        $this->assertContains('/series/global/hp/', $all);
        $this->assertNotContains('/series/global/twilight/', $all, '日本語訳の無いコレクション名は選ばない');
        $this->assertNotContains('/series/global/thin/', $all, 'noindex のページは選ばない');
        $this->assertNotContains('/series/global/empty/', $all, '書き出されないページは選ばない');
        $this->assertNotContains('/series/global/x/', $all);
        $this->assertNotContains('/series/global/unknown/', $all);
        $this->assertContains('ハリー・ポッター シリーズ', array_column(PopularSeriesLinks::pick($series, $brands), 'name'));
    }

    public function test_japan_uses_wikipedia_names_and_skips_duplicates(): void
    {
        $series = ['region' => 'japan', 'series' => [
            $this->series('名探偵コナン', '名探偵コナン', '/series/名探偵コナン/', 1_694, [], 'totalYen'),
            $this->series('one-piece', 'ONE PIECE', '/series/one-piece/', 584, [], 'totalYen'),
            $this->series('dup', '名探偵コナン', '/series/dup/', 10, [], 'totalYen'),
        ]];
        $brands = ['region' => 'japan', 'kind' => 'brand', 'series' => [
            $this->series('ghibli', 'スタジオジブリ', '/series/brand/ghibli/', 1_387, ['titleName' => 'スタジオジブリ映画'], 'totalYen'),
        ]];

        $links = PopularSeriesLinks::pick($series, $brands);

        $this->assertSame(
            ['名探偵コナン', 'ONE PIECE', 'スタジオジブリ映画'],
            array_column($links, 'name')
        );
        $this->assertSame('/series/名探偵コナン/', $links[0]['href']);
    }

    public function test_japan_snapshot_puts_conan_first_and_only_links_listed_pages(): void
    {
        $catalog = SeriesCatalog::japan([]);
        $links = PopularSeriesLinks::pick($catalog, ['region' => 'japan', 'series' => []]);

        $this->assertCount(PopularSeriesLinks::SERIES_LIMIT, $links);
        $this->assertSame('名探偵コナン', $links[0]['name']);
        $this->assertSame('/series/名探偵コナン/', $links[0]['href']);
        $byPath = collect($catalog['series'])->keyBy('path');
        foreach ($links as $link) {
            $page = $byPath->get($link['href']);
            $this->assertIsArray($page, $link['href']);
            $this->assertTrue((bool) $page['indexable'], $link['href']);
            $this->assertTrue(SeriesCatalog::hasListedFilms($page), $link['href']);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function series(string $slug, string $name, string $path, ?int $total, array $overrides = [], string $key = 'totalUsd'): array
    {
        return array_merge([
            'slug' => $slug,
            'name' => $name,
            'path' => $path,
            'filmCount' => 3,
            'films' => [['id' => 'f-'.$slug]],
            'indexable' => true,
            'totalUsd' => null,
            'totalYen' => null,
            $key => $total === null ? null : $total * 1_000_000,
        ], $overrides);
    }
}
