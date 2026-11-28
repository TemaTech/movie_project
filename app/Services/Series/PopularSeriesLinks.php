<?php

namespace App\Services\Series;

/**
 * トップ（世界）と /japan/（日本）に並べる「人気のシリーズ・ブランド」リンク。
 *
 * 書き出し対象のシリーズ／ブランドページだけを、その地域の合計興収が大きい順に選ぶ。
 * シリーズ上位 SERIES_LIMIT 件 → ブランド上位 BRAND_LIMIT 件の順に並べる。
 */
class PopularSeriesLinks
{
    public const SERIES_LIMIT = 7;

    public const BRAND_LIMIT = 3;

    /**
     * @param  array<string, mixed>  $seriesCatalog  SeriesCatalog::japan() / world() の結果
     * @param  array<string, mixed>  $brandCatalog  BrandCatalog::japan() / world() の結果
     * @return list<array{name: string, href: string, kind: string}>
     */
    public static function pick(
        array $seriesCatalog,
        array $brandCatalog,
        int $seriesLimit = self::SERIES_LIMIT,
        int $brandLimit = self::BRAND_LIMIT,
    ): array {
        $links = [];
        $seen = [];
        foreach ([
            [$seriesCatalog, $seriesLimit, false],
            [$brandCatalog, $brandLimit, true],
        ] as [$catalog, $limit, $isBrand]) {
            $added = 0;
            foreach (self::rankedCandidates($catalog) as $item) {
                if ($added >= $limit) {
                    break;
                }
                $href = (string) $item['path'];
                $name = $isBrand ? self::brandLabel($item) : self::seriesLabel($item, $catalog);
                if ($name === null || isset($seen['href:'.$href]) || isset($seen['name:'.$name])) {
                    continue;
                }
                $seen['href:'.$href] = true;
                $seen['name:'.$name] = true;
                $links[] = ['name' => $name, 'href' => $href, 'kind' => $isBrand ? 'brand' : 'series'];
                $added++;
            }
        }

        return $links;
    }

    /**
     * 書き出されて検索対象にもなるページだけを、合計興収の大きい順に返す。
     *
     * @param  array<string, mixed>  $catalog
     * @return list<array<string, mixed>>
     */
    private static function rankedCandidates(array $catalog): array
    {
        $key = ($catalog['region'] ?? '') === 'japan' ? 'totalYen' : 'totalUsd';
        $candidates = [];
        foreach ($catalog['series'] ?? [] as $item) {
            if (! is_array($item)
                || (string) ($item['slug'] ?? '') === ''
                || ! str_starts_with((string) ($item['path'] ?? ''), '/series/')
                || empty($item['indexable'])
                || ! SeriesCatalog::hasListedFilms($item)
                || ! is_numeric($item[$key] ?? null)
                || (int) $item[$key] <= 0) {
                continue;
            }
            $candidates[] = $item;
        }
        usort($candidates, fn (array $a, array $b): int => ((int) $b[$key]) <=> ((int) $a[$key]));

        return $candidates;
    }

    /**
     * 日本のシリーズ名は Wikipedia の日本語名をそのまま使う。
     * 世界のシリーズ名は TMDB コレクション名で、日本語訳が無い（かなを含まない）ものは選ばない。
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $catalog
     */
    private static function seriesLabel(array $series, array $catalog): ?string
    {
        $name = trim((string) ($series['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        if (($catalog['region'] ?? '') === 'japan') {
            return $name;
        }
        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $name) !== 1) {
            return null;
        }
        $bare = preg_replace('/[\s　]*(コレクション|Collection|シリーズ|Series)$/iu', '', $name);
        $bare = trim(is_string($bare) && $bare !== '' ? $bare : $name);

        return $bare.' シリーズ';
    }

    /**
     * ブランドは「MCU映画」「スタジオジブリ映画」のように、何の作品群か分かる titleName を使う。
     *
     * @param  array<string, mixed>  $brand
     */
    private static function brandLabel(array $brand): ?string
    {
        $name = trim((string) ($brand['titleName'] ?? $brand['name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
