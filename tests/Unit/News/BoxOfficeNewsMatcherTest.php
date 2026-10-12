<?php

namespace Tests\Unit\News;

use App\Services\News\BoxOfficeNewsMatcher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class BoxOfficeNewsMatcherTest extends TestCase
{
    public function test_it_keeps_a_trusted_box_office_headline_and_drops_the_rest(): void
    {
        $now = new DateTimeImmutable('2026-10-12T12:00:00+09:00');
        $articles = [
            $this->article(
                '『バイオハザード』公開3日間で興収6.2億円',
                'hit',
                'オリコン',
                '世界興収も更新',
                '2026-10-12T19:56:00+09:00',
            ),
            $this->article(
                '『バイオハザード』フィギュアが登場',
                'figure',
                'オリコン',
                '予約開始',
                '2026-10-11T10:00:00+09:00',
            ),
            $this->article(
                '『バイオハザード』興収が話題',
                'gossip',
                '週刊女性PRIME',
                '興行収入も突破',
                '2026-10-11T10:00:00+09:00',
            ),
            $this->article(
                '別作品の興行収入',
                'other',
                '日刊スポーツ',
                '動員も好調',
                '2026-10-11T10:00:00+09:00',
            ),
            $this->article(
                '『バイオハザード』興収10億円',
                'old',
                'シネマトゥデイ',
                '当時の記録',
                '2026-08-01T10:00:00+09:00',
            ),
        ];

        $matched = (new BoxOfficeNewsMatcher())->match('バイオハザード：デスアイランド', null, $articles, $now);

        $this->assertSame(['https://news.yahoo.co.jp/articles/hit'], array_column($matched, 'url'));
        $this->assertArrayNotHasKey('snippet', $matched[0]);
    }

    public function test_it_matches_a_shortened_headline_against_the_quoted_title(): void
    {
        $matched = (new BoxOfficeNewsMatcher())->match(
            '劇場版「鬼滅の刃」無限城編',
            null,
            [
                $this->article(
                    '『鬼滅の刃』興行収入100億円突破',
                    'kimetsu',
                    '映画ナタリー',
                    '公開から好調',
                    '2026-10-10T09:00:00+09:00',
                ),
            ],
            new DateTimeImmutable('2026-10-12T12:00:00+09:00'),
        );

        $this->assertCount(1, $matched);
        $this->assertSame('映画ナタリー', $matched[0]['source']);
    }

    /**
     * @return array{title: string, url: string, source: string, snippet: string, publishedAt: string}
     */
    private function article(string $title, string $id, string $source, string $snippet, string $publishedAt): array
    {
        return [
            'title' => $title,
            'url' => 'https://news.yahoo.co.jp/articles/'.$id,
            'source' => $source,
            'snippet' => $snippet,
            'publishedAt' => $publishedAt,
        ];
    }
}
