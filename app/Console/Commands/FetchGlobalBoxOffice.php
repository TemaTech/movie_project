<?php

namespace App\Console\Commands;

use App\Console\Traits\SavesMovieImages;
use App\Models\GlobalMovie;
use App\Services\BoxOffice\GlobalRankingAssembler;
use App\Services\BoxOffice\HistoryRecorder;
use App\Services\BoxOffice\MovieIdentity;
use App\Services\BoxOffice\TheatricalRun;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FetchGlobalBoxOffice extends Command
{
    use SavesMovieImages;

    private const TARGET_MOVIES = 200;

    private const MAX_DISCOVER_PAGES = 20;

    private const MAX_DISCOVER_PAGE_RETRIES = 3;

    private HistoryRecorder $history;

    private TmdbClient $tmdb;

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $globalObservations = null;

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
                $this->pause(15);

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

            $movies = $this->appendPreviousRankingMovies($movies, $existingAnalyses);

            $callsBeforeSeries = $this->tmdb->httpCalls();
            $extras = $this->expandCollectionMovies($movies);
            $movies = $this->promoteHighGrossingExtras($movies, $extras, $existingAnalyses);
            $movies = GlobalRankingAssembler::finalize($movies, self::TARGET_MOVIES);
            $extras = $this->expandCollectionMovies($movies);

            if (count($movies) < HistoryRecorder::MINIMUM_MOVIES) {
                $this->error(sprintf(
                    '取得件数が%d件で下限%d件を下回ったため、履歴もデータベースも更新しません',
                    count($movies),
                    HistoryRecorder::MINIMUM_MOVIES
                ));

                return self::FAILURE;
            }

            $this->persistMovies($movies);
            $this->persistSeriesExtras($extras);
            $this->info(sprintf(
                '処理完了: ランキング%d件 / シリーズ追加%d件 / TMDb HTTP %d回（うちシリーズ展開 +%d）',
                count($movies),
                count($extras),
                $this->tmdb->httpCalls(),
                $this->tmdb->httpCalls() - $callsBeforeSeries
            ));

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
                $this->pause(10 * $attempt);
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

            $this->pause(1);
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
            $englishDetails = $this->tmdb->getMovie($tmdbId, 'en-US', ['release_dates']);

            if ($movieDetails === null) {
                $this->error("映画ID {$tmdbId} の詳細取得に失敗しました");
                Log::error('個別の映画データ取得でエラー:', [
                    'movie_id' => $tmdbId,
                    'error' => 'Invalid or incomplete TMDb response',
                ]);

                return null;
            }

            // 興行収入は作品ページと同じ英語（en-US）の revenue を使う。
            // TMDB は言語ごとにこの値を持っていて、日本語訳だけ更新が遅れることがある。
            $revenue = (int) ($englishDetails['revenue'] ?? 0);
            if ($revenue === 0 || $revenue === 1000000000) {
                $revenue = (int) ($movieDetails['revenue'] ?? ($movie['revenue'] ?? 0));
            }

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

            $voteCount = $movie['vote_count']
                ?? $movieDetails['vote_count']
                ?? (is_array($englishDetails) ? ($englishDetails['vote_count'] ?? null) : null);
            if ($voteCount !== null && (int) $voteCount < 100) {
                $this->info(sprintf(
                    'スキップ: %s (投票数: %d - データの信頼性が低い)',
                    $movie['title'] ?? $movieDetails['title'],
                    (int) $voteCount
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
            $theatrical = TheatricalRun::assess(
                is_string($releaseDate) ? $releaseDate : null,
                is_array($englishDetails) ? ($englishDetails['release_dates']['results'] ?? []) : [],
                now('Asia/Tokyo')->toDateTimeImmutable(),
                $this->observationsFor($key),
                $revenue,
            );

            $this->history->resolve([
                'region' => 'global',
                'title' => $movieDetails['title'] ?? $movie['title'],
                'tmdbId' => $tmdbId,
                'releaseYear' => $year,
                'releaseDate' => $releaseDate,
                'releaseDatePrecision' => $releaseDate ? 'day' : null,
                'currentReleaseDate' => $theatrical['revivalDate'],
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

            $collection = is_array($movieDetails['belongs_to_collection'] ?? null)
                ? $movieDetails['belongs_to_collection']
                : (is_array($englishDetails['belongs_to_collection'] ?? null) ? $englishDetails['belongs_to_collection'] : null);

            $isActive = $theatrical['active'];
            if ($theatrical['revivalDate']) {
                $this->info(sprintf(
                    '興収が伸びたリバイバル上映として公開中にします: %s (%s)',
                    $movieDetails['title'] ?? $movie['title'],
                    $theatrical['revivalDate']
                ));
            }

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
                'in_ranking' => 1,
                'tmdb_collection_id' => is_array($collection) ? ($collection['id'] ?? null) : null,
                'tmdb_collection_name' => is_array($collection) ? ($collection['name'] ?? null) : null,
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
     * @return list<array<string, mixed>>
     */
    private function observationsFor(string $key): array
    {
        if ($this->globalObservations === null) {
            $this->globalObservations = $this->history->observations()->loadByKey('global');
        }

        return $this->globalObservations[$key] ?? [];
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

        if (Schema::hasColumn('global_movies', 'in_ranking')) {
            GlobalMovie::where('region', 'global')->where('in_ranking', true)->delete();
        } else {
            GlobalMovie::where('region', 'global')->delete();
        }
        $this->info('既存のランキングデータを削除しました');

        DB::beginTransaction();
        $this->info('トランザクションを開始しました');

        try {
            $chunks = array_chunk(array_map(fn (array $movie) => $this->globalMovieInsertRow($movie), $movies), 50);
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

    /**
     * @param  list<array<string, mixed>>  $rankingMovies
     * @return list<array<string, mixed>>
     */
    private function expandCollectionMovies(array $rankingMovies): array
    {
        if (! Schema::hasColumn('global_movies', 'in_ranking')) {
            $this->warn('in_ranking 列がないためシリーズ作品の追加取得をスキップします');

            return [];
        }

        $rankingIds = [];
        $collections = [];
        foreach ($rankingMovies as $movie) {
            $tmdbId = (int) ($movie['tmdb_id'] ?? 0);
            if ($tmdbId > 0) {
                $rankingIds[$tmdbId] = true;
            }
            $collectionId = (int) ($movie['tmdb_collection_id'] ?? 0);
            if ($collectionId > 0) {
                $collections[$collectionId] = (string) ($movie['tmdb_collection_name'] ?? '');
            }
        }

        if ($collections === []) {
            $this->info('ランキング作品にTMDbコレクションがありません');

            return [];
        }

        $this->info(sprintf('シリーズ展開: %d コレクション', count($collections)));
        $extras = [];
        $seen = $rankingIds;

        foreach ($collections as $collectionId => $fallbackName) {
            $collection = $this->loadCollection((int) $collectionId);
            if ($collection === null) {
                $this->warn(sprintf('コレクション %d を取得できませんでした', $collectionId));

                continue;
            }

            $name = (string) ($collection['name'] ?? $fallbackName);
            foreach ($collection['parts'] ?? [] as $part) {
                $partId = (int) ($part['id'] ?? 0);
                if ($partId === 0 || isset($seen[$partId])) {
                    continue;
                }
                $seen[$partId] = true;
                $record = $this->buildSeriesExtraRecord($part, $name, (int) $collectionId);
                if ($record !== null) {
                    $extras[] = $record;
                }
            }
        }

        $this->info(sprintf('シリーズ追加作品: %d件', count($extras)));

        return $extras;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCollection(int $collectionId): ?array
    {
        $cache = storage_path('app/tmdb-series/collections/'.$collectionId.'.json');
        if (File::exists($cache)) {
            $cached = json_decode(File::get($cache), true);
            if (is_array($cached) && isset($cached['parts'])) {
                return $cached;
            }
        }

        $collection = $this->tmdb->getCollection($collectionId, 'ja');
        if ($collection === null) {
            $collection = $this->tmdb->getCollection($collectionId, 'en-US');
        }
        if ($collection === null) {
            return null;
        }

        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $collection;
    }

    /**
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>|null
     */
    private function buildSeriesExtraRecord(array $part, string $collectionName, int $collectionId): ?array
    {
        $tmdbId = (int) ($part['id'] ?? 0);
        if ($tmdbId === 0) {
            return null;
        }

        $partRelease = is_string($part['release_date'] ?? null) ? $part['release_date'] : null;
        $unreleased = is_string($partRelease) && $partRelease > now('Asia/Tokyo')->toDateString();
        $details = $unreleased ? [] : ($this->loadSeriesMovieDetails($tmdbId) ?? []);
        if ($details === [] && ! $unreleased) {
            $this->warn(sprintf('シリーズ作品 %d の詳細を取得できませんでした。コレクションの作品名のみ残します', $tmdbId));
        }

        $revenue = (int) ($details['revenue'] ?? 0);
        if ($revenue === 1_000_000_000 || $revenue > 3_000_000_000) {
            $revenue = 0;
        }

        $releaseDate = $details['release_date'] ?? $partRelease;
        $title = (string) ($details['title'] ?? ($part['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $key = MovieIdentity::globalKey($tmdbId);
        $genres = isset($details['genres'])
            ? json_encode(collect($details['genres'])->pluck('name')->toArray())
            : json_encode([]);

        $localPosterPath = null;
        if (! empty($details['poster_path'])) {
            $localPosterPath = $this->downloadAndSaveImage($details['poster_path'], $key);
        }

        $collectionTitle = is_array($details['belongs_to_collection'] ?? null)
            ? ($details['belongs_to_collection']['name'] ?? $collectionName)
            : $collectionName;

        return [
            'movie_id' => $key,
            'tmdb_id' => $tmdbId,
            'title' => $title,
            'original_title' => $details['original_title'] ?? ($part['original_title'] ?? null),
            'poster_path' => $localPosterPath,
            'box_office' => $revenue,
            'budget' => (int) ($details['budget'] ?? 0),
            'release_date' => $releaseDate ?: null,
            'release_date_precision' => $releaseDate ? 'day' : null,
            'rank' => null,
            'region' => 'global',
            'is_active' => 0,
            'in_ranking' => 0,
            'tmdb_collection_id' => $collectionId,
            'tmdb_collection_name' => $collectionTitle,
            'genres' => $genres,
            'production_country' => isset($details['production_countries'][0])
                ? $details['production_countries'][0]['iso_3166_1']
                : null,
            'data_source' => 'TMDb',
            'data_source_url' => "https://www.themoviedb.org/movie/{$tmdbId}",
            'last_updated' => now(),
            'ai_analysis' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>
     */
    private function globalMovieInsertRow(array $movie): array
    {
        if (! Schema::hasColumn('global_movies', 'in_ranking')) {
            unset($movie['in_ranking'], $movie['tmdb_collection_id'], $movie['tmdb_collection_name']);
        }

        return $movie;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSeriesMovieDetails(int $tmdbId): ?array
    {
        $cache = storage_path('app/tmdb-series/movies/'.$tmdbId.'.json');
        if (File::exists($cache)) {
            $cached = json_decode(File::get($cache), true);
            if (is_array($cached) && isset($cached['id'])) {
                return $cached;
            }
        }

        $details = $this->tmdb->getMovie($tmdbId, 'ja');
        $revenue = (int) ($details['revenue'] ?? 0);
        if ($details === null || $revenue === 0 || $revenue === 1_000_000_000) {
            $english = $this->tmdb->getMovie($tmdbId, 'en-US');
            if (is_array($english)) {
                if ($details === null) {
                    $details = $english;
                } elseif ((int) ($english['revenue'] ?? 0) > 0) {
                    $details['revenue'] = $english['revenue'];
                    $details['belongs_to_collection'] = $details['belongs_to_collection']
                        ?? ($english['belongs_to_collection'] ?? null);
                }
            }
        }

        if ($details === null) {
            return null;
        }

        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $details;
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     */
    private function persistSeriesExtras(array $movies): void
    {
        if (! Schema::hasColumn('global_movies', 'in_ranking')) {
            return;
        }

        GlobalMovie::where('region', 'global')->where('in_ranking', false)->delete();
        if ($movies === []) {
            return;
        }

        foreach (array_chunk(array_map(fn (array $movie) => $this->globalMovieInsertRow($movie), $movies), 50) as $chunk) {
            GlobalMovie::insert($chunk);
        }
        $this->info(sprintf('シリーズ追加作品を%d件保存しました', count($movies)));
    }

    /**
     * Discover に欠けている直前の歴代作品を、同じフィルタで取り直して足す。
     *
     * @param  list<array<string, mixed>>  $movies
     * @param  array<int|string, mixed>  $existingAnalyses
     * @return list<array<string, mixed>>
     */
    private function appendPreviousRankingMovies(array $movies, array $existingAnalyses): array
    {
        $have = $this->movieIds($movies);
        $added = 0;

        foreach ($this->previousRankingTmdbIds() as $tmdbId) {
            if (isset($have[$tmdbId])) {
                continue;
            }

            $record = $this->buildMovieRecord(['id' => $tmdbId], count($movies) + 1, $existingAnalyses);
            if ($record === null) {
                continue;
            }
            $movies[] = $record;
            $have[$tmdbId] = true;
            $added++;
        }

        if ($added > 0) {
            $this->info(sprintf('直前の歴代から %d 件を補完しました', $added));
        }

        return $movies;
    }

    /**
     * シリーズ追加のうち、今の200位より興収が高い作品を同じフィルタで歴代候補に上げる。
     *
     * @param  list<array<string, mixed>>  $movies
     * @param  list<array<string, mixed>>  $extras
     * @param  array<int|string, mixed>  $existingAnalyses
     * @return list<array<string, mixed>>
     */
    private function promoteHighGrossingExtras(array $movies, array $extras, array $existingAnalyses): array
    {
        $have = $this->movieIds($movies);
        $cutoff = GlobalRankingAssembler::cutoff($movies, self::TARGET_MOVIES);
        $added = 0;

        foreach ($extras as $extra) {
            $tmdbId = (int) ($extra['tmdb_id'] ?? 0);
            $boxOffice = (int) ($extra['box_office'] ?? 0);
            if ($tmdbId <= 0 || isset($have[$tmdbId]) || $boxOffice <= $cutoff) {
                continue;
            }

            $record = $this->buildMovieRecord([
                'id' => $tmdbId,
                'title' => $extra['title'] ?? null,
                'release_date' => $extra['release_date'] ?? null,
            ], count($movies) + 1, $existingAnalyses);
            if ($record === null) {
                continue;
            }
            $movies[] = $record;
            $have[$tmdbId] = true;
            $added++;
        }

        if ($added > 0) {
            $this->info(sprintf('シリーズ追加から %d 件を歴代へ上げました', $added));
        }

        return $movies;
    }

    /**
     * @return list<int>
     */
    private function previousRankingTmdbIds(): array
    {
        $ids = [];
        try {
            $query = GlobalMovie::query()->where('region', 'global')->whereNotNull('tmdb_id');
            if (Schema::hasColumn('global_movies', 'in_ranking')) {
                $query->where('in_ranking', true);
            }
            foreach ($query->pluck('tmdb_id') as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        } catch (\Throwable) {
            // CI の空 DB やドライバなしでは履歴だけを使う
        }

        foreach ($this->history->registry()->movies() as $movie) {
            if (($movie['region'] ?? '') !== 'global') {
                continue;
            }
            $id = (int) ($movie['tmdbId'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<array<string, mixed>>  $movies
     * @return array<int, true>
     */
    private function movieIds(array $movies): array
    {
        $ids = [];
        foreach ($movies as $movie) {
            $id = (int) ($movie['tmdb_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function pause(int $seconds): void
    {
        if ($seconds <= 0 || app()->runningUnitTests()) {
            return;
        }

        sleep($seconds);
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
