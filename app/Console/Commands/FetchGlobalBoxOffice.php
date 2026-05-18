<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\GlobalMovie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\BoxOfficeFetchError;
use App\Console\Traits\SavesMovieImages;

class FetchGlobalBoxOffice extends Command
{
    use SavesMovieImages;

    protected $signature = 'movies:fetch-global-boxoffice';
    protected $description = '世界の映画興行収入データを取得・更新します（日本を除く）';

    public function handle()
    {
        try {
            $api_key = config('services.tmdb.api_key');
            $this->info('TMDb APIを使用してデータ取得を開始...');

            // 既存のAI分析データを事前に退避（DB接続タイムアウト回避のため早期に取得）
            $existingAnalyses = GlobalMovie::where('region', 'global')
                ->whereNotNull('ai_analysis')
                ->pluck('ai_analysis', 'tmdb_id')
                ->toArray();
            $this->info(sprintf('既存のAI分析データを%d件保持しています', count($existingAnalyses)));

            $movies = [];
            $page = 1;
            $totalMovies = 0;

            // 200件になるまでページングして取得
            while ($totalMovies < 200) {
                $response = Http::get('https://api.themoviedb.org/3/discover/movie', [
                    'api_key' => $api_key,
                    'sort_by' => 'revenue.desc',
                    'language' => 'ja',
                    'page' => $page,
                    'include_adult' => false,
                    'include_video' => false
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $pageMovies = $data['results'];
                    
                    foreach ($pageMovies as $movie) {
                        try {
                            // 映画の詳細情報を取得
                            $movieDetails = Http::get("https://api.themoviedb.org/3/movie/{$movie['id']}", [
                                'api_key' => $api_key,
                                'language' => 'ja'
                            ])->json();

                            // 英語のタイトルを別途取得（original_titleが非英語の場合や確実に英語名が欲しい場合のため）
                            $englishDetails = Http::get("https://api.themoviedb.org/3/movie/{$movie['id']}", [
                                'api_key' => $api_key,
                                'language' => 'en-US'
                            ])->json();

                            // データ品質チェック
                            // 1. 興行収入が0または丁度10億ドルの場合はスキップ
                            if (empty($movieDetails['revenue']) || $movieDetails['revenue'] === 1000000000) {
                                $this->info(sprintf(
                                    'スキップ: %s (興行収入: $%s - 信頼性の低いデータ)',
                                    $movie['title'],
                                    number_format($movieDetails['revenue'])
                                ));
                                continue;
                            }

                            // 2. 興行収入が30億ドル以上の場合はスキップ（Avatarの最高記録は約29億ドル）
                            if ($movieDetails['revenue'] > 3000000000) {
                                $this->info(sprintf(
                                    'スキップ: %s (興行収入: $%s - 異常に高い値)',
                                    $movie['title'],
                                    number_format($movieDetails['revenue'])
                                ));
                                continue;
                            }

                            // 3. 投票数が少なすぎる映画はスキップ（信頼性が低い）
                            if (isset($movie['vote_count']) && $movie['vote_count'] < 100) {
                                $this->info(sprintf(
                                    'スキップ: %s (投票数: %d - データの信頼性が低い)',
                                    $movie['title'],
                                    $movie['vote_count']
                                ));
                                continue;
                            }

                            // 4. リリース年が1970年より前の映画はスキップ（データの信頼性が低い）
                            if (!empty($movie['release_date'])) {
                                $releaseYear = (int)substr($movie['release_date'], 0, 4);
                                if ($releaseYear < 1970) {
                                    $this->info(sprintf(
                                        'スキップ: %s (公開年: %d - 古すぎるデータ)',
                                        $movie['title'],
                                        $releaseYear
                                    ));
                                    continue;
                                }
                            }

                            $totalMovies++;
                            $rank = $totalMovies;

                            // genresをJSON文字列に変換
                            $genres = isset($movieDetails['genres']) 
                                ? json_encode(collect($movieDetails['genres'])->pluck('name')->toArray())
                                : json_encode([]);

                            // ポスター画像の保存処理
                            $localPosterPath = null;
                            if (!empty($movieDetails['poster_path'])) {
                                $filenameBase = 'global_' . sprintf('%03d', $rank) . '_' . $movie['id'];
                                $localPosterPath = $this->downloadAndSaveImage($movieDetails['poster_path'], $filenameBase);
                                if ($localPosterPath) {
                                    $this->info("ポスター画像を保存しました: {$localPosterPath}");
                                }
                            }

                            $movies[] = [
                                'movie_id' => 'global_' . sprintf('%03d', $rank) . '_' . $movie['id'],
                                'tmdb_id' => $movie['id'],
                                'title' => $movieDetails['title'] ?? $movie['title'],
                                'original_title' => $englishDetails['title'] ?? ($movieDetails['original_title'] ?? null),
                                'poster_path' => $localPosterPath,
                                'box_office' => $movieDetails['revenue'] ?? 0,
                                'budget' => $movieDetails['budget'] ?? 0,
                                'release_date' => !empty($movie['release_date']) ? $movie['release_date'] : null,
                                'rank' => $rank,
                                'region' => 'global',
                                'genres' => $genres,  // JSON文字列として保存
                                'production_country' => isset($movieDetails['production_countries'][0]) 
                                    ? $movieDetails['production_countries'][0]['iso_3166_1'] 
                                    : null,
                                'data_source' => 'TMDb',
                                'data_source_url' => "https://www.themoviedb.org/movie/{$movie['id']}",
                                'last_updated' => now(),
                                'ai_analysis' => $existingAnalyses[$movie['id']] ?? null
                            ];

                            $this->info(sprintf(
                                'データ取得完了 [%d/200]: %s (興行収入: $%s)',
                                $rank,
                                $movie['title'],
                                number_format($movieDetails['revenue'])
                            ));

                            // 200件に達したら終了
                            if ($totalMovies >= 200) {
                                break;
                            }
                        } catch (\Exception $e) {
                            $this->error("映画ID {$movie['id']} の処理中にエラー: " . $e->getMessage());
                            Log::error('個別の映画データ取得でエラー:', [
                                'movie_id' => $movie['id'],
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }

                    $page++;

                    // APIの制限に配慮して少し待機
                    sleep(1);
                } else {
                    $this->error('TMDb APIからのデータ取得に失敗しました');
                    Log::error('TMDb API error:', [
                        'status' => $response->status(),
                        'response' => $response->json()
                    ]);
                    break;
                }
            }

            // データベースへの一括登録
            if (!empty($movies)) {
                $this->info('データベースへの一括登録を開始...');
                
                // 既存のトランザクションがあれば終了
                try {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                        $this->info('既存のトランザクションをロールバックしました');
                    }
                } catch (\Exception $e) {
                    // 既存のトランザクションがない場合は無視
                }
                
                try {
                    // 既存のデータを一旦全て削除（truncateは暗黙的にコミットするため、トランザクション外で実行）
                    GlobalMovie::where('region', 'global')->delete();
                    $this->info('既存のデータを削除しました');
                    
                    DB::beginTransaction();
                    $this->info('トランザクションを開始しました');
                    
                    // チャンクに分割してバルクインサート
                    foreach (array_chunk($movies, 50) as $chunkIndex => $chunk) {
                        GlobalMovie::insert($chunk);
                        $this->info(sprintf('チャンク %d/%d を挿入しました', $chunkIndex + 1, count(array_chunk($movies, 50))));
                    }
                    
                    DB::commit();
                    $this->info('トランザクションをコミットしました');
                    $this->info(sprintf('データベースに%d件の映画データを保存しました', count($movies)));
                } catch (\Exception $e) {
                    $this->error('データベース操作中にエラーが発生しました: ' . $e->getMessage());
                    Log::error('Database operation error:', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // トランザクションがアクティブかチェックしてからロールバック
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                        $this->info('トランザクションをロールバックしました');
                    }
                    throw $e;
                }
            }

            $this->info("処理完了: 合計{$totalMovies}件の映画データを更新しました");

        } catch (\Exception $e) {
            $this->error('エラー発生: ' . $e->getMessage());
            Log::error('Global box office fetch error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // エラーメール送信（一時停止中）
            // try {
            //     Mail::to('horiuchi.cadd9@gmail.com')->send(new BoxOfficeFetchError($e, '世界歴代興行成績'));
            //     $this->info('エラー通知メールを送信しました');
            // } catch (\Exception $mailException) {
            //     $this->error('エラー通知メールの送信に失敗しました: ' . $mailException->getMessage());
            // }
            
            // 最終的なトランザクションのクリーンアップ
            try {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                    $this->info('最終的なトランザクションをロールバックしました');
                }
            } catch (\Exception $cleanupError) {
                $this->error('トランザクションクリーンアップ中にエラー: ' . $cleanupError->getMessage());
            }
        }
    }
}
 