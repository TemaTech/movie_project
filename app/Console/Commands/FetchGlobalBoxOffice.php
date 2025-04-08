<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\GlobalMovie;
use Illuminate\Support\Facades\DB;

class FetchGlobalBoxOffice extends Command
{
    protected $signature = 'movies:fetch-global-boxoffice';
    protected $description = '世界の映画興行収入データを取得・更新します（日本を除く）';

    public function handle()
    {
        try {
            $api_key = config('services.tmdb.api_key');
            $this->info('TMDb APIを使用してデータ取得を開始...');

            $movies = [];
            $page = 1;
            $totalMovies = 0;

            // 100件になるまでページングして取得
            while ($totalMovies < 100) {
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

                            // 興行収入が0または丁度10億ドルの場合はスキップ
                            if (empty($movieDetails['revenue']) || $movieDetails['revenue'] === 1000000000) {
                                $this->info(sprintf(
                                    'スキップ: %s (興行収入: $%s - 信頼性の低いデータ)',
                                    $movie['title'],
                                    number_format($movieDetails['revenue'])
                                ));
                                continue;
                            }

                            $totalMovies++;
                            $rank = $totalMovies;

                            // genresをJSON文字列に変換
                            $genres = isset($movieDetails['genres']) 
                                ? json_encode(collect($movieDetails['genres'])->pluck('name')->toArray())
                                : json_encode([]);

                            $movies[] = [
                                'movie_id' => 'global_' . sprintf('%03d', $rank) . '_' . $movie['id'],
                                'title' => $movie['title'],
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
                                'last_updated' => now()
                            ];

                            $this->info(sprintf(
                                'データ取得完了 [%d/100]: %s (興行収入: $%s)',
                                $rank,
                                $movie['title'],
                                number_format($movieDetails['revenue'])
                            ));

                            // 100件に達したら終了
                            if ($totalMovies >= 100) {
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
                
                DB::transaction(function () use ($movies) {
                    // 既存のデータを一旦全て削除
                    GlobalMovie::truncate();
                    
                    // チャンクに分割してバルクインサート
                    foreach (array_chunk($movies, 50) as $chunk) {
                        GlobalMovie::insert($chunk);
                    }
                });
                
                $this->info(sprintf('データベースに%d件の映画データを保存しました', count($movies)));
            }

            $this->info("処理完了: 合計{$totalMovies}件の映画データを更新しました");

        } catch (\Exception $e) {
            $this->error('エラー発生: ' . $e->getMessage());
            Log::error('Global box office fetch error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 