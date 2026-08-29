<?php

namespace App\Console\Commands;

use App\Console\Traits\SavesMovieImages;
use App\Models\GlobalMovie;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\BoxOffice\MovieIdentity;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchGlobalBoxOffice extends Command
{
    use SavesMovieImages;

    private const TARGET_MOVIES = 200;

    private const MAX_DISCOVER_PAGES = 20;

    private const MAX_DISCOVER_PAGE_RETRIES = 3;

    private HistoryRecorder $history;

    private TmdbClient $tmdb;

    protected $signature = 'movies:fetch-global-boxoffice';

    protected $description = '世界の映画興行収入データを取得・更新します（日本を除く）';

    public function handle(): int
    {
        try {
            $this->tmdb = TmdbClient::fromConfig();
            $this->info('TMDb APIを使用してデータ取得を開始...');
            $this->history = HistoryRecorder::fromConfig();

            $existingAnalyses = GlobalMovie::where('region', 'global')
                ->whereNotNull('ai_analysis')
                ->pluck('ai_analysis', 'tmdb_id')
                ->toArray();
            $this->info(sprintf('既存のAI分析データを%d件保持しています', count($existingAnalyses)));

            $candidates = $this->collectDiscoverCandidates();
            if ($candidates === []) {
                $this->error('TMDb APIから候補作品を取得できませんでした');

                return self::FAILURE;
            }

            $movies = [];
            $deferred = [];

            foreach ($candidates as $candidate) {
                if (count($movies) >= self::TARGET_MOVIES) {
                    break;
                }

                $record = $this->buildMovieRecord($candidate, count($movies) + 1, $existingAnalyses);
                if ($record === null) {
                    $deferred[] = $candidate;

                    continue;
                }

                $movies[] = $record;
            }

            if ($deferred !== [] && count($movies) < self::TARGET_MOVIES) {
                $this->warn(sprintf('%d件の取得失敗分を再試行します', count($deferred)));
                sleep(15);

                foreach ($deferred as $candidate) {
                    if (count($movies) >= self::TARGET_MOVIES) {
                        break;
                    }

                    $record = $this->buildMovieRecord($candidate, count($movies) + 1, $existingAnalyses);
                    if ($record === null) {
                        $this->error(sprintf('映画ID %d の再試行後も取得に失敗しました', $candidate['id']));

                        continue;
                    }

                    $movies[] = $record;
                }
            }

            if ($movies === []) {
                $this->error('有効な映画データを1件も取得できませんでした');

                return self::FAILURE;
            }

            if (count($movies) < HistoryRecorder::MINIMUM_MOVIES) {
                $this->error(sprintf(
                    '取得件数が%d件で下限%d件を下回ったため、履歴もデータベースも更新しません',
                    count($movies),
                    HistoryRecorder::MINIMUM_MOVIES
                ));

                return self::FAILURE;
            }

            $this->persistMovies($movies);
            $this->info(sprintf('処理完了: 合計%d件の映画データを更新しました', count($movies)));

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('エラー発生: '.$e->getMessage());
            Log::error('Global box office fetch error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->rollbackOpenTransaction();

            return self::FAILURE;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectDiscoverCandidates(): array
    {
        $candidates = [];
        $seenIds = [];

        for ($page = 1; $page <= self::MAX_DISCOVER_PAGES && count($candidates) < self::TARGET_MOVIES * 2; $page++) {
            $pageMovies = null;

            for ($attempt = 1; $attempt <= self::MAX_DISCOVER_PAGE_RETRIES; $attempt++) {
                $pageMovies = $this->tmdb->discoverMovies($page);

                if ($pageMovies !== null) {
                    break;
                }

                $this->warn(sprintf('Discover page %d の取得に失敗しました (%d/%d)', $page, $attempt, self::MAX_DISCOVER_PAGE_RETRIES));
                sleep(10 * $attempt);
            }

            if ($pageMovies === null) {
                $this->error(sprintf('Discover page %d を%d回試行しても取得できませんでした', $page, self::MAX_DISCOVER_PAGE_RETRIES));
                break;
            }

            if ($pageMovies === []) {
                break;
            }

            foreach ($pageMovies as $movie) {
                $id = (int) ($movie['id'] ?? 0);
                if ($id === 0 || isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $candidates[] = $movie;
            }

            sleep(1);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $movie
     * @param  array<int|string, mixed>  $existingAnalyses
     * @return array<string, mixed>|null
     */
    private function buildMovieRecord(array $movie, int $rank, array $existingAnalyses): ?array
    {
        try {
            $tmdbId = (int) $movie['id'];
            $movieDetails = $this->tmdb->getMovie($tmdbId, 'ja');
            $englishDetails = $this->tmdb->getMovie($tmdbId, 'en-US');

            if ($movieDetails === null) {
                $this->error("映画ID {$tmdbId} の詳細取得に失敗しました");
                Log::error('個別の映画データ取得でエラー:', [
                    'movie_id' => $tmdbId,
                    'error' => 'Invalid or incomplete TMDb response',
                ]);

                return null;
            }

            $revenue = (int) ($movieDetails['revenue'] ?? ($movie['revenue'] ?? 0));

            if ($revenue === 0 || $revenue === 1000000000) {
                $this->info(sprintf(
                    'スキップ: %s (興行収入: $%s - 信頼性の低いデータ)',
                    $movie['title'] ?? $movieDetails['title'],
                    number_format($revenue)
                ));

                return null;
            }

            if ($revenue > 3000000000) {
                $this->info(sprintf(
                    'スキップ: %s (興行収入: $%s - 異常に高い値)',
                    $movie['title'] ?? $movieDetails['title'],
                    number_format($revenue)
                ));

                return null;
            }

            if (isset($movie['vote_count']) && (int) $movie['vote_count'] < 100) {
                $this->info(sprintf(
                    'スキップ: %s (投票数: %d - データの信頼性が低い)',
                    $movie['title'] ?? $movieDetails['title'],
                    (int) $movie['vote_count']
                ));

                return null;
            }

            $releaseDate = ! empty($movie['release_date'])
                ? $movie['release_date']
                : ($movieDetails['release_date'] ?? null);

            if (! empty($releaseDate)) {
                $releaseYear = (int) substr($releaseDate, 0, 4);
                if ($releaseYear < 1970) {
                    $this->info(sprintf(
                        'スキップ: %s (公開年: %d - 古すぎるデータ)',
                        $movie['title'] ?? $movieDetails['title'],
                        $releaseYear
                    ));

                    return null;
                }
            }

            $key = MovieIdentity::globalKey($tmdbId);
            $year = $releaseDate ? (int) substr($releaseDate, 0, 4) : null;

            $this->history->resolve([
                'region' => 'global',
                'title' => $movieDetails['title'] ?? $movie['title'],
                'tmdbId' => $tmdbId,
                'releaseYear' => $year,
                'releaseDate' => $releaseDate,
                'releaseDatePrecision' => $releaseDate ? 'day' : null,
                'legacyIds' => [
                    MovieIdentity::globalLegacyId($rank, $tmdbId),
                    MovieIdentity::globalLegacySlug($rank, $tmdbId),
                ],
                'now' => now('Asia/Tokyo')->toIso8601String(),
            ]);

            $genres = isset($movieDetails['genres'])
                ? json_encode(collect($movieDetails['genres'])->pluck('name')->toArray())
                : json_encode([]);

            $localPosterPath = null;
            if (! empty($movieDetails['poster_path'])) {
                $localPosterPath = $this->downloadAndSaveImage($movieDetails['poster_path'], $key);
                if ($localPosterPath) {
                    $this->info("ポスター画像を保存しました: {$localPosterPath}");
                }
            }

            $isActive = $releaseDate
                && $releaseDate >= now('Asia/Tokyo')->subMonths(6)->toDateString();

            $this->info(sprintf(
                'データ取得完了 [%d/%d]: %s (興行収入: $%s)',
                $rank,
                self::TARGET_MOVIES,
                $movieDetails['title'] ?? $movie['title'],
                number_format($revenue)
            ));

            return [
                'movie_id' => $key,
                'tmdb_id' => $tmdbId,
                'title' => $movieDetails['title'] ?? $movie['title'],
                'original_title' => $englishDetails['title'] ?? ($movieDetails['original_title'] ?? null),
                'poster_path' => $localPosterPath,
                'box_office' => $revenue,
                'budget' => (int) ($movieDetails['budget'] ?? 0),
                'release_date' => $releaseDate,
                'release_date_precision' => $releaseDate ? 'day' : null,
                'rank' => $rank,
                'region' => 'global',
                'is_active' => $isActive ? 1 : 0,
                'genres' => $genres,
                'production_country' => isset($movieDetails['production_countries'][0])
                    ? $movieDetails['production_countries'][0]['iso_3166_1']
                    : null,
                'data_source' => 'TMDb',
                'data_source_url' => "https://www.themoviedb.org/movie/{$tmdbId}",
                'last_updated' => now(),
                'ai_analysis' => $existingAnalyses[$tmdbId] ?? null,
            ];
        } catch (\Exception $e) {
            $this->error("映画ID {$movie['id']} の処理中にエラー: ".$e->getMessage());
            Log::error('個別の映画データ取得でエラー:', [
                'movie_id' => $movie['id'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     */
    private function persistMovies(array $movies): void
    {
        $this->info('データベースへの一括登録を開始...');

        foreach ($this->history->recordSnapshot('global', $movies, now('Asia/Tokyo')) as $warning) {
            $this->warn($warning);
        }

        $this->rollbackOpenTransaction();

        GlobalMovie::where('region', 'global')->delete();
        $this->info('既存のデータを削除しました');

        DB::beginTransaction();
        $this->info('トランザクションを開始しました');

        try {
            $chunks = array_chunk($movies, 50);
            foreach ($chunks as $chunkIndex => $chunk) {
                GlobalMovie::insert($chunk);
                $this->info(sprintf('チャンク %d/%d を挿入しました', $chunkIndex + 1, count($chunks)));
            }

            DB::commit();
            $this->info('トランザクションをコミットしました');
            $this->info(sprintf('データベースに%d件の映画データを保存しました', count($movies)));
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
                $this->info('トランザクションをロールバックしました');
            }

            throw $e;
        }
    }

    private function rollbackOpenTransaction(): void
    {
        try {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
                $this->info('既存のトランザクションをロールバックしました');
            }
        } catch (\Exception) {
            // 既存のトランザクションがない場合は無視
        }
    }
}
