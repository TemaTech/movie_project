<?php

namespace App\Services\BoxOffice;

class GlobalRankingAssembler
{
    /**
     * 現在の候補を興収順にしたときの、上位 $limit 件に入る最低興収。
     * 候補が空なら 0。
     *
     * @param  list<array<string, mixed>>  $movies
     */
    public static function cutoff(array $movies, int $limit = 200): int
    {
        if ($movies === [] || $limit <= 0) {
            return 0;
        }

        $sorted = self::sortByBoxOffice($movies);
        $index = min(count($sorted), $limit) - 1;

        return (int) ($sorted[$index]['box_office'] ?? 0);
    }

    /**
     * 興収の高い順に最大 $limit 件を歴代として確定し、順位を振り直す。
     *
     * @param  list<array<string, mixed>>  $movies
     * @return list<array<string, mixed>>
     */
    public static function finalize(array $movies, int $limit = 200): array
    {
        $unique = [];
        foreach ($movies as $movie) {
            if (! is_array($movie)) {
                continue;
            }
            $id = (int) ($movie['tmdb_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $existing = $unique[$id] ?? null;
            if ($existing === null || (int) ($movie['box_office'] ?? 0) > (int) ($existing['box_office'] ?? 0)) {
                $unique[$id] = $movie;
            }
        }

        $sorted = self::sortByBoxOffice(array_values($unique));
        $ranking = array_slice($sorted, 0, max(0, $limit));
        foreach ($ranking as $index => $movie) {
            $ranking[$index]['rank'] = $index + 1;
            $ranking[$index]['in_ranking'] = 1;
        }

        return $ranking;
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @return list<array<string, mixed>>
     */
    public static function sortByBoxOffice(array $movies): array
    {
        usort(
            $movies,
            function (array $a, array $b): int {
                $cmp = ((int) ($b['box_office'] ?? 0)) <=> ((int) ($a['box_office'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((int) ($a['tmdb_id'] ?? 0)) <=> ((int) ($b['tmdb_id'] ?? 0));
            }
        );

        return $movies;
    }
}
