<?php

namespace Tests\Unit\Series;

use App\Services\Series\SeriesCatalog;
use App\Services\Series\SeriesFilmMatcher;
use App\Services\Series\SeriesMembership;
use Tests\TestCase;

class SeriesMembershipTest extends TestCase
{
    public function test_jurassic_world_belongs_to_jurassic_park_series(): void
    {
        $series = ['name' => 'ジュラシック・パーク'];
        $this->assertTrue(SeriesMembership::belongs($series, ['title' => 'ジュラシック・パーク']));
        $this->assertTrue(SeriesMembership::belongs($series, ['title' => 'ジュラシック・ワールド']));
        $this->assertTrue(SeriesMembership::belongs($series, ['title' => 'ロスト・ワールド/ジュラシック・パーク']));
        $this->assertFalse(SeriesMembership::belongs($series, ['title' => 'アバター']));
    }

    public function test_bond_titles_belong_to_007(): void
    {
        $series = ['name' => '007'];
        $this->assertTrue(SeriesMembership::belongs($series, ['title' => '007/私を愛したスパイ']));
        $this->assertTrue(SeriesMembership::belongs($series, ['title' => '007 ゴールドフィンガー']));
        $this->assertFalse(SeriesMembership::belongs($series, ['title' => 'ミッション:インポッシブル']));
    }

    public function test_stand_by_me_does_not_steal_from_doraemon_when_longer_alias_wins(): void
    {
        $doraemon = ['name' => 'ドラえもん'];
        $standByMe = ['name' => 'STAND BY ME ドラえもん'];
        $film = ['title' => 'STAND BY ME ドラえもん'];
        $assigned = SeriesMembership::assign([$doraemon, $standByMe], $film);
        $this->assertSame('STAND BY ME ドラえもん', $assigned['series']['name']);
        $this->assertSame([], $assigned['ambiguous']);
    }

    public function test_strict_matcher_does_not_fuzzy_match_unrelated_titles(): void
    {
        $matched = SeriesFilmMatcher::match([
            'title' => '名探偵コナン ハイウェイの堕天使',
            'releaseYear' => 2026,
        ], [[
            'id' => 'other',
            'title' => '名探偵ピカチュウ',
            'releaseYear' => 2019,
        ]]);
        $this->assertNull($matched);
    }

    public function test_strict_matcher_links_conan_despite_particle_difference(): void
    {
        $matched = SeriesFilmMatcher::match([
            'title' => 'ハリー・ポッター 賢者の石',
            'releaseYear' => 2001,
        ], [[
            'id' => 'jp-1',
            'title' => 'ハリー・ポッターと賢者の石',
            'releaseYear' => 2001,
            'rank' => 10,
        ]]);
        $this->assertSame('jp-1', $matched['id']);
    }

    public function test_identity_compact_collapses_wikipedia_and_ranking_harry_potter_titles(): void
    {
        $this->assertSame(
            SeriesFilmMatcher::identityCompact('ハリー・ポッター 賢者の石'),
            SeriesFilmMatcher::identityCompact('ハリー・ポッターと賢者の石 (映画)')
        );
        $this->assertSame(
            ['tmdb:671', 'title:ハリーポッター賢者石|2001'],
            SeriesFilmMatcher::dedupeKeys([
                'id' => 'tmdb-671',
                'tmdbId' => 671,
                'title' => 'ハリー・ポッターと賢者の石 (映画)',
                'releaseYear' => 2001,
            ])
        );
    }

    public function test_display_title_strips_wikipedia_disambiguation_suffix(): void
    {
        $this->assertSame('スパイダーマン', SeriesFilmMatcher::displayTitle('スパイダーマン (2002年の映画)'));
        $this->assertSame(
            SeriesFilmMatcher::identityCompact('スパイダーマン'),
            SeriesFilmMatcher::identityCompact('スパイダーマン (2002年の映画)')
        );
        $matched = SeriesFilmMatcher::match([
            'title' => 'スパイダーマン',
            'releaseYear' => 2002,
        ], [[
            'id' => 'jp-557',
            'title' => 'スパイダーマン (2002年の映画)',
            'releaseYear' => 2002,
            'rank' => 107,
        ]]);
        $this->assertSame('jp-557', $matched['id']);
    }

    public function test_same_film_treats_yearless_duplicate_as_one_title(): void
    {
        $this->assertTrue(SeriesFilmMatcher::isSameFilm(
            ['title' => 'ハリー・ポッターと賢者の石', 'releaseYear' => 2001],
            ['title' => 'ハリー・ポッター 賢者の石', 'releaseYear' => null],
        ));
        $this->assertFalse(SeriesFilmMatcher::isSameFilm(
            ['title' => 'HERO', 'releaseYear' => 2002],
            ['title' => 'HERO', 'releaseYear' => 2007],
        ));
        $this->assertTrue(SeriesFilmMatcher::isSameFilm(
            [
                'title' => 'クレイヴン・ザ・ハンター',
                'wikipediaTitle' => 'クレイヴン・ザ・ハンター (映画)',
                'releaseYear' => 2022,
            ],
            [
                'title' => 'クレイヴン・ザ・ハンター',
                'wikipediaTitle' => 'クレイヴン・ザ・ハンター (映画)',
                'releaseYear' => 2024,
            ],
        ));
        $this->assertFalse(SeriesFilmMatcher::isSameFilm(
            [
                'title' => 'HERO',
                'wikipediaTitle' => 'HERO (2007年の映画)',
                'releaseYear' => 2007,
            ],
            [
                'title' => 'HERO',
                'wikipediaTitle' => 'HERO (2015年の映画)',
                'releaseYear' => 2015,
            ],
        ));
    }

    public function test_catalog_dedupes_same_wikipedia_title_across_years(): void
    {
        $deduped = SeriesCatalog::dedupeFilms([
            [
                'title' => 'クレイヴン・ザ・ハンター',
                'wikipediaTitle' => 'クレイヴン・ザ・ハンター (映画)',
                'releaseYear' => 2022,
                'id' => 'jp-series-95df6fb18c8c',
            ],
            [
                'title' => 'クレイヴン・ザ・ハンター',
                'wikipediaTitle' => 'クレイヴン・ザ・ハンター (映画)',
                'releaseYear' => 2024,
                'id' => 'jp-series-08d5dd3a454b',
            ],
        ]);
        $this->assertCount(1, $deduped);
        $this->assertSame('クレイヴン・ザ・ハンター', $deduped[0]['title']);
    }

    public function test_catalog_adds_year_only_when_titles_collide(): void
    {
        $films = SeriesCatalog::disambiguateDuplicateTitles([
            ['id' => 'a', 'title' => 'HERO', 'releaseYear' => 2007],
            ['id' => 'b', 'title' => 'HERO', 'releaseYear' => 2015],
            ['id' => 'c', 'title' => 'ワンダーウーマン', 'releaseYear' => 2017],
        ]);
        $this->assertSame('HERO (2007)', $films[0]['title']);
        $this->assertSame('HERO (2015)', $films[1]['title']);
        $this->assertSame('ワンダーウーマン', $films[2]['title']);
    }
}
